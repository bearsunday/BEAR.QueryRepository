<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\NullSemanticLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * An ETag can outlive the entry it was issued with while a newer entry sits under the same URI:
 * the invalidation that should have dropped it failed in the ETag pool alone. Neither
 * `HttpCache::isNotModified()` nor `isNotModifiedFor()` answers 304 for it - the client would
 * keep the replaced representation until the ETag's own TTL ran out.
 */
class EtagOfReplacedEntryTest extends TestCase
{
    /** @param array<string, int> $body */
    private function ro(string $uri, array $body): Index
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
            new CacheDependencyCollector(new DependsOnTags(new UriTag()), new CacheDependency(new UriTag()), new NullSemanticLogger()),
            new BaseUriTag(new UriTag()),
            new CacheTags(new UriTag(), new BaseUriTag(new UriTag())),
        );
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function pools(): iterable
    {
        yield 'separate pools, the ETag pool refuses the cleanup' => [true, true];
        yield 'one pool, the cleanup is refused and the save is not' => [false, true];
        yield 'separate pools, both healthy' => [true, false];
    }

    #[DataProvider('pools')]
    public function testTheEtagOfAnEntryASecondWriteReplacedIsNotAnswered304(bool $separate, bool $refuseCleanup): void
    {
        $roPool = new TagAwareAdapter(new ArrayAdapter());
        $etagPool = $separate ? new TagAwareAdapter(new ArrayAdapter()) : $roPool;
        $storage = ResourceStorageTest::getResourceStorageInstance(roPool: $roPool, etagPool: $etagPool);

        $ro = $this->ro('page://self/x', ['v' => 1]);
        $this->assertTrue($this->repository($storage)->put($ro));
        $replacedEtag = (string) $ro->headers[Header::ETAG];

        $secondEtagPool = $refuseCleanup ? new FakeRefusingPool($etagPool, refuseSave: false, refuseInvalidation: true) : $etagPool;
        $secondStorage = ResourceStorageTest::getResourceStorageInstance(
            roPool: $separate ? $roPool : $secondEtagPool,
            etagPool: $secondEtagPool,
        );
        $ro2 = $this->ro('page://self/x', ['v' => 2]);
        $this->assertTrue($this->repository($secondStorage)->put($ro2));
        $currentEtag = (string) $ro2->headers[Header::ETAG];

        $this->assertNotSame($replacedEtag, $currentEtag);
        $this->assertSame(['v' => 2], $storage->get($ro->uri)?->body, 'precondition: the entry is the second write');
        $this->assertNotModified($storage, $ro->uri, $replacedEtag, false);
        $this->assertNotModified($storage, $ro->uri, $currentEtag, true);
    }

    /**
     * A donut refresh saves the recomposed view with no cleanup of its own, so the ETag an
     * invalidation left behind is not removed by the next write even once the ETag pool is back.
     */
    public function testTheEtagOfAViewARefreshReplacedAfterAPurgeThatFailedInTheEtagPoolIsNotAnswered304(): void
    {
        $roPool = new TagAwareAdapter(new ArrayAdapter());
        $etagPool = new TagAwareAdapter(new ArrayAdapter());
        $storage = ResourceStorageTest::getResourceStorageInstance(roPool: $roPool, etagPool: $etagPool);
        $uriTag = (new UriTag())(new Uri('page://self/d'));

        $this->saveView($storage, 'v1', '"etag-1"');
        $purging = ResourceStorageTest::getResourceStorageInstance(
            roPool: $roPool,
            etagPool: new FakeRefusingPool($etagPool, refuseSave: false, refuseInvalidation: true),
        );
        $this->assertFalse($purging->invalidateTags([$uriTag]));
        $this->saveView($storage, 'v2', '"etag-2"');

        $uri = new Uri('page://self/d');
        $this->assertSame('v2', $storage->get($uri)?->view, 'precondition: the view is the recomposed one');
        $this->assertNotModified($storage, $uri, '"etag-1"', false);
        $this->assertNotModified($storage, $uri, '"etag-2"', true);
        $this->assertNotModified($storage, $uri, 'W/"etag-2"', true);
        $this->assertNotModified($storage, $uri, '"etag-1", "etag-2"', true);
    }

    /** The two saves DonutRepository::saveView() makes */
    private function saveView(ResourceStorage $storage, string $view, string $etag): void
    {
        $ro = $this->ro('page://self/d', []);
        $ro->view = $view;
        $ro->headers[Header::ETAG] = $etag;
        $this->assertTrue($storage->saveDonutView($ro, 10, []));
        $storage->saveEtag($ro->uri, $etag, '', 10);
    }

    private function assertNotModified(ResourceStorage $storage, AbstractUri $uri, string $ifNoneMatch, bool $expected): void
    {
        $httpCache = new HttpCache($storage);
        $server = [Header::HTTP_IF_NONE_MATCH => $ifNoneMatch];
        $this->assertSame($expected, $httpCache->isNotModifiedFor($uri, $server), 'isNotModifiedFor ' . $ifNoneMatch);
        $this->assertSame($expected, $httpCache->isNotModified($server), 'isNotModified ' . $ifNoneMatch);
    }
}
