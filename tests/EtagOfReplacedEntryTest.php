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

/** An entry holds one validator: the validator of the entry a later write replaced is not answered 304 */
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
            new CacheTags(new UriTag(), new BaseUriTag(new UriTag())),
        );
    }

    /** @return iterable<string, array{bool}> */
    public static function cleanups(): iterable
    {
        yield 'the cleanup drops the replaced entry' => [false];
        yield 'the pool refuses the cleanup and takes the save' => [true];
    }

    #[DataProvider('cleanups')]
    public function testTheEtagOfAnEntryASecondWriteReplacedIsNotAnswered304(bool $refuseCleanup): void
    {
        $pool = new TagAwareAdapter(new ArrayAdapter());
        $storage = ResourceStorageTest::getResourceStorageInstance(roPool: $pool);

        $ro = $this->ro('page://self/x', ['v' => 1]);
        $this->assertTrue($this->repository($storage)->put($ro));
        $replacedEtag = (string) $ro->headers[Header::ETAG];

        $secondStorage = ResourceStorageTest::getResourceStorageInstance(
            roPool: $refuseCleanup ? new FakeRefusingPool($pool, refuseSave: false, refuseInvalidation: true) : $pool,
        );
        $ro2 = $this->ro('page://self/x', ['v' => 2]);
        $this->assertTrue($this->repository($secondStorage)->put($ro2));
        $currentEtag = (string) $ro2->headers[Header::ETAG];

        $this->assertNotSame($replacedEtag, $currentEtag);
        $this->assertSame(['v' => 2], $storage->get($ro->uri)?->body, 'precondition: the entry is the second write');
        $this->assertNotModified($storage, $ro->uri, $replacedEtag, false);
        $this->assertNotModified($storage, $ro->uri, $currentEtag, true);
    }

    /** A donut refresh saves the recomposed view with no cleanup of its own. */
    public function testTheEtagOfAViewARefreshReplacedIsNotAnswered304(): void
    {
        $storage = ResourceStorageTest::getResourceStorageInstance(roPool: new TagAwareAdapter(new ArrayAdapter()));

        $etag1 = $this->saveView($storage, 'v1', '"etag-1"');
        $etag2 = $this->saveView($storage, 'v2', '"etag-2"');

        $uri = new Uri('page://self/d');
        $this->assertSame('v2', $storage->get($uri)?->view, 'precondition: the view is the recomposed one');
        $this->assertNotModified($storage, $uri, $etag1, false);
        $this->assertNotModified($storage, $uri, $etag2, true);
        $this->assertNotModified($storage, $uri, 'W/' . $etag2, true);
        $this->assertNotModified($storage, $uri, $etag1 . ', ' . $etag2, true);
    }

    private function saveView(ResourceStorage $storage, string $view, string $etag): string
    {
        $ro = $this->ro('page://self/d', []);
        $ro->view = $view;
        $ro->headers[Header::ETAG] = $etag;
        $this->assertTrue($storage->saveDonutView($ro, 10, []));

        return (string) $ro->headers[Header::ETAG];
    }

    private function assertNotModified(ResourceStorage $storage, AbstractUri $uri, string $ifNoneMatch, bool $expected): void
    {
        $httpCache = new HttpCache($storage);
        $server = [Header::HTTP_IF_NONE_MATCH => $ifNoneMatch];
        $this->assertSame($expected, $httpCache->isNotModifiedFor($uri, $server), 'isNotModifiedFor ' . $ifNoneMatch);
        $this->assertSame($expected, $httpCache->isNotModified($server), 'isNotModified ' . $ifNoneMatch);
    }
}
