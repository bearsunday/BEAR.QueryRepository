<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\NullSemanticLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function sleep;

/**
 * An ETag can remain without the entry it validates - a cleanup that failed in just the ETag
 * pool, an invalidation landing between the two saves a put() makes, or a shorter TTL on the
 * entry than on its ETag. Neither `HttpCache::isNotModified()` nor `isNotModifiedFor()` answers
 * 304 from the ETag alone in any of the three (#266).
 */
class EtagWithoutEntryTest extends TestCase
{
    /** @param array<string, int> $body */
    private function ro(string $uri, array $body = ['v' => 1]): Index
    {
        $ro = new Index();
        $ro->uri = new Uri($uri);
        $ro->code = 200;
        $ro->body = $body;

        return $ro;
    }

    private function repository(ResourceStorageInterface $storage): QueryRepository
    {
        return new QueryRepository(
            new NullSemanticLogger(),
            new HeaderSetter(new EtagSetter()),
            $storage,
            new Expiry(),
            new CacheDependency(new UriTag()),
            new BaseUriTag(new UriTag()),
        );
    }

    /**
     * cleanUpBeforeWrite() ignores deleteEtag()'s return value. With separate pools, an
     * invalidation that succeeds in the RO pool and fails in the ETag pool leaves the old,
     * still-live ETag behind; a pool that then refuses the new entry leaves no entry at all.
     */
    public function testAPreWriteCleanupThatFailsInTheEtagPoolFollowedByARefusedEntryIsNotAnswered304(): void
    {
        $roPool = new TagAwareAdapter(new ArrayAdapter());
        $etagPool = new TagAwareAdapter(new ArrayAdapter());
        $storage = ResourceStorageTest::getResourceStorageInstance(roPool: $roPool, etagPool: $etagPool);

        $ro = $this->ro('page://self/x');
        $this->assertTrue($this->repository($storage)->put($ro), 'precondition: the first write stores the entry and its ETag');
        $staleEtag = (string) $ro->headers[Header::ETAG];

        // The next write: cleanUpBeforeWrite() invalidates the old entry - the RO pool succeeds,
        // the ETag pool refuses - and the RO pool then refuses the new entry outright.
        $refusingRoPool = new FakeRefusingPool($roPool);
        $refusingEtagPool = new FakeRefusingPool($etagPool, refuseSave: false, refuseInvalidation: true);
        $refusingStorage = ResourceStorageTest::getResourceStorageInstance(roPool: $refusingRoPool, etagPool: $refusingEtagPool);

        $ro2 = $this->ro('page://self/x', ['v' => 2]);
        $this->assertFalse($this->repository($refusingStorage)->put($ro2), 'the RO pool refuses the new entry');

        $this->assertNull($storage->get($ro->uri), 'precondition: the entry is gone');
        $httpCache = new HttpCache($storage);
        $this->assertFalse(
            $httpCache->isNotModifiedFor($ro->uri, [Header::HTTP_IF_NONE_MATCH => $staleEtag]),
            'a 304 here answers for a response the pool does not hold (#266)',
        );
        $this->assertFalse(
            $httpCache->isNotModified([Header::HTTP_IF_NONE_MATCH => $staleEtag]),
            'the pre-routing answer must not 304 either (#266)',
        );
    }

    /**
     * put()'s own two writes - saveValue() then saveEtag() - are not atomic. An invalidation
     * (another request, a #[Purge] write) landing between them removes the entry the ETag about
     * to be saved would validate; put() has no way to notice and saves it anyway.
     */
    public function testAnEtagLeftByAnInvalidationBetweenTheTwoSavesIsNotAnswered304(): void
    {
        $storage = ResourceStorageTest::getResourceStorageInstance(
            roPool: new TagAwareAdapter(new ArrayAdapter()),
            etagPool: new TagAwareAdapter(new ArrayAdapter()),
        );
        $ro = $this->ro('page://self/y');
        $etag = '"y-etag"';

        $this->assertTrue($storage->saveValue($ro, 10));
        $storage->invalidateTags([(new UriTag())($ro->uri)]);
        $storage->saveEtag($ro->uri, $etag, '', 10);

        $this->assertNull($storage->get($ro->uri), 'precondition: the entry did not survive the invalidation');
        $httpCache = new HttpCache($storage);
        $this->assertFalse(
            $httpCache->isNotModifiedFor($ro->uri, [Header::HTTP_IF_NONE_MATCH => $etag]),
            'a 304 here answers for a response the pool does not hold (#266)',
        );
        $this->assertFalse(
            $httpCache->isNotModified([Header::HTTP_IF_NONE_MATCH => $etag]),
            'the pre-routing answer must not 304 either (#266)',
        );
    }

    /**
     * Both the entry and its ETag are saved with expiresAfter($ttl) at their own save time, so
     * the one saved second outlives the other by the gap between the two saves - here, a delay
     * long enough for the entry's own TTL to lapse before its ETag is saved.
     */
    public function testAnEtagThatOutlivesItsExpiredEntryIsNotAnswered304(): void
    {
        $storage = ResourceStorageTest::getResourceStorageInstance(
            roPool: new TagAwareAdapter(new ArrayAdapter()),
            etagPool: new TagAwareAdapter(new ArrayAdapter()),
        );
        $ro = $this->ro('page://self/z');
        $etag = '"z-etag"';

        $this->assertTrue($storage->saveValue($ro, 1));
        sleep(2); // the delay between put()'s own saveValue() and saveEtag() calls
        $storage->saveEtag($ro->uri, $etag, '', 1);

        $this->assertNull($storage->get($ro->uri), 'precondition: the entry has expired');
        $httpCache = new HttpCache($storage);
        $this->assertFalse(
            $httpCache->isNotModifiedFor($ro->uri, [Header::HTTP_IF_NONE_MATCH => $etag]),
            'a 304 here answers for a response the pool does not hold (#266)',
        );
        $this->assertFalse(
            $httpCache->isNotModified([Header::HTTP_IF_NONE_MATCH => $etag]),
            'the pre-routing answer must not 304 either (#266)',
        );
    }
}
