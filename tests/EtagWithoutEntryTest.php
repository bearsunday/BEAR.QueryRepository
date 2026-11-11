<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\NullSemanticLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function sleep;

/** A validator is answered 304 while the entry that holds it is stored, and not once that entry is gone (#266) */
class EtagWithoutEntryTest extends TestCase
{
    private TagAwareAdapterInterface $pool;
    private ResourceStorage $storage;

    protected function setUp(): void
    {
        $this->pool = new TagAwareAdapter(new ArrayAdapter());
        $this->storage = ResourceStorageTest::getResourceStorageInstance(roPool: $this->pool);
    }

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
            new CacheDependencyCollector(new DependsOnTags(new UriTag()), new CacheDependency(new UriTag()), new NullSemanticLogger()),
            new CacheTags(new UriTag(), new BaseUriTag(new UriTag())),
        );
    }

    public function testTheEtagOfAnInvalidatedEntryIsNotAnswered304(): void
    {
        $ro = $this->ro('page://self/y');
        $this->assertTrue($this->repository($this->storage)->put($ro));
        $etag = (string) $ro->headers[Header::ETAG];
        $this->assertNotModified($ro->uri, $etag, true);

        $this->assertTrue($this->storage->invalidateTags([(new UriTag())($ro->uri)]));

        $this->assertNull($this->storage->get($ro->uri), 'precondition: the entry did not survive the invalidation');
        $this->assertNotModified($ro->uri, $etag, false);
    }

    public function testTheEtagOfAnExpiredEntryIsNotAnswered304(): void
    {
        $ro = $this->ro('page://self/z');
        $ro->headers[Header::ETAG] = '"z-etag"';
        $this->assertTrue($this->storage->saveValue($ro, 1));
        $etag = (string) $ro->headers[Header::ETAG];
        $this->assertNotModified($ro->uri, $etag, true);

        sleep(2);

        $this->assertNull($this->storage->get($ro->uri), 'precondition: the entry has expired');
        $this->assertNotModified($ro->uri, $etag, false);
    }

    /** The cleanup before a replacing write drops the stored entry; a pool that then refuses the new one leaves none */
    public function testNoEtagIsAnswered304WhenThePoolRefusesTheEntryThatReplacesAnother(): void
    {
        $ro = $this->ro('page://self/x');
        $this->assertTrue($this->repository($this->storage)->put($ro));
        $replacedEtag = (string) $ro->headers[Header::ETAG];
        $this->assertNotModified($ro->uri, $replacedEtag, true);

        $refusing = ResourceStorageTest::getResourceStorageInstance(roPool: new FakeRefusingPool($this->pool));
        $ro2 = $this->ro('page://self/x', ['v' => 2]);
        $this->assertFalse($this->repository($refusing)->put($ro2), 'the pool refuses the new entry');
        $refusedEtag = (string) $ro2->headers[Header::ETAG];

        $this->assertNotSame($replacedEtag, $refusedEtag);
        $this->assertNull($this->storage->get($ro->uri), 'precondition: the entry is gone');
        $this->assertNotModified($ro->uri, $replacedEtag, false);
        $this->assertNotModified($ro->uri, $refusedEtag, false);
    }

    private function assertNotModified(AbstractUri $uri, string $ifNoneMatch, bool $expected): void
    {
        $httpCache = new HttpCache($this->storage);
        $server = [Header::HTTP_IF_NONE_MATCH => $ifNoneMatch];
        $this->assertSame($expected, $httpCache->isNotModifiedFor($uri, $server), 'isNotModifiedFor ' . $ifNoneMatch);
        $this->assertSame($expected, $httpCache->isNotModified($server), 'isNotModified ' . $ifNoneMatch);
    }
}
