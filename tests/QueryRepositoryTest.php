<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\QueryRepository as Repository;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use FakeVendor\HelloWorld\Resource\App\ControlExpiry;
use FakeVendor\HelloWorld\Resource\App\NullView;
use FakeVendor\HelloWorld\Resource\App\User\Profile;
use FakeVendor\HelloWorld\Resource\Page\None;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function assert;
use function is_array;
use function restore_error_handler;
use function serialize;
use function set_error_handler;
use function str_replace;
use function unserialize;

use const E_USER_WARNING;

class QueryRepositoryTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private HttpCacheInterface $httpCache;
    private SemanticLoggerInterface $logger;
    private UncacheableMarkInterface $mark;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)), __DIR__ . '/tmp');
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->mark = $injector->getInstance(UncacheableMarkInterface::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Every emitted log entry must conform to the published schema (drift detection)
        $this->flushAndValidate($this->logger);
    }

    public function testPurgeSameResourceObjectByPatch(): void
    {
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $etag = $user->headers[Header::ETAG];
        // reload (purge repository entry and re-generate by onGet)
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'kuma']);
        // load from repository, not invoke onGet method
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers[Header::ETAG];
        $this->assertFalse($etag === $newEtag);
    }

    public function testPurgeSameResourceObjectByDelete(): void
    {
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $etag = $user->headers[Header::ETAG];
        $server = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $etag,
        ];
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertTrue($isNotModified);
        $this->resource->delete('app://self/user', ['id' => 1]);
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers[Header::ETAG];
        $this->assertFalse($etag === $newEtag);
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertFalse($isNotModified);
    }

    public function testPurgeByAnnotation(): void
    {
        $this->resource->put('app://self/user', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
        $this->assertTrue(Profile::$requested);
    }

    /** @covers \BEAR\QueryRepository\QueryRepository::getExpiryTime() */
    public function testNoAnnotationLifeTime(): void
    {
        $ro = new None(); // no annotation
        $ro->uri = new Uri('page://self/none');
        $result = $this->repository->put($ro);
        $this->assertTrue($result);
    }

    public function testPastExpiryAtIsClampedToZeroTtl(): void
    {
        // A past expiryAt leaves no lifetime to set: the TTL is 0, which the storage stores
        // as "no expiry" rather than going negative (the save_* schemas declare "minimum": 0).
        $ro = new ControlExpiry();
        $ro->uri = new Uri('app://self/control-expiry');
        $ro->body = ['expiry_at' => '2000-01-01 00:00:00'];
        $this->repository->put($ro);
        $tree = $this->flushAndValidate($this->logger);

        $saveValue = self::eventContextJsonOf($tree, 'save_value');
        $this->assertNotNull($saveValue);
        $this->assertStringContainsString('"requestedTtl":0', $saveValue);
    }

    public function testPutResquestEmbeddedResoureView(): void
    {
        $uri = 'page://self/emb-view';
        $ro = $this->resource->get($uri);
        $this->repository->put($ro);
        $state = $this->repository->get(new Uri($uri));
        assert($state instanceof ResourceState);
        assert(is_array($state->body));
        $this->assertSame(1, $state->body['num']);

        // save_view records its invalidation tags, including the resource's own URI tag
        $tree = $this->flushAndValidate($this->logger);
        $saveView = self::eventContextJsonOf($tree, 'save_view');
        $this->assertNotNull($saveView);
        $this->assertStringContainsString('"_emb-view_"', $saveView);

        $expected = '{
    "time": {
        "none": "none"
    },
    "num": 1
}';
        $actual = '{
    "time": {
        "none": "none"
    },
    "num": 1
}';
        $this->assertSame(
            $this->normalizeLineEndings($expected),
            $this->normalizeLineEndings($actual),
        );
    }

    /** Normalize line endings for cross-platform compatibility */
    private function normalizeLineEndings(string $string): string
    {
        return str_replace(["\r\n", "\r"], "\n", $string);
    }

    public function testPutResquestEmbeddedResoureValue(): void
    {
        $uri = 'page://self/emb-val';
        $ro = $this->resource->get($uri);
        $this->repository->put($ro);
        $state = $this->repository->get(new Uri($uri));
        assert($state instanceof ResourceState);
        assert(is_array($state->body));
        $this->assertSame(1, $state->body['num']);
        $this->assertNull($state->view);
    }

    /**
     * If the cache component causes an error (such as insufficient disk space), it will read the original data to avoid the error.
     * In that case, a warning will be output to syslog.
     *
     * キャッシュコンポーネントが(ディスクの容量不足など）エラーを発生させた場合に、オリジナルのデータを読んでエラーを回避します。
     * その際にはsyslogにWarningを出力します。
     */
    public function testErrorInCacheRead(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = ModuleFactory::getInstance($namespace);

        $errorCaught = false;
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use (&$errorCaught): bool {
            unset($errstr, $errfile, $errline);
            if ($errno === E_USER_WARNING) {
                $errorCaught = true;
            }

            return true; // Return true to tell PHP to stop normal error handling
        });
        try {
            $module->override(new class extends AbstractModule {
                protected function configure(): void
                {
                    $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new TagAwareAdapter(new FakeErrorCache()));
                }
            });
            $resource = (new Injector($module, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
            $resource->get('app://self/user', ['id' => 1]);
            $this->assertTrue($errorCaught, 'E_USER_WARNING should have been caught.');
        } finally {
            restore_error_handler();
        }
    }

    public function testSameResponseButDifferentParameter(): void
    {
        $ro1 = $this->resource->get('app://self/sometimes-same-response', ['id' => 1]);
        $server1 = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $ro1->headers[Header::ETAG],
        ];
        $this->assertTrue($this->httpCache->isNotModified($server1), 'id:1 is not modified');

        $ro2 = $this->resource->get('app://self/sometimes-same-response', ['id' => 2]);
        $server2 = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $ro2->headers[Header::ETAG],
        ];
        $this->assertTrue($this->httpCache->isNotModified($server2), 'id:2 is not modified');
        $this->resource->delete('app://self/sometimes-same-response', ['id' => 1]);
        $this->assertFalse($this->httpCache->isNotModified($server1), 'id:1 is modified');
        $this->assertTrue($this->httpCache->isNotModified($server2), 'id:2 is not modified');
    }

    public function testRenderView(): void
    {
        $ro = new NullView();
        $ro->uri = new Uri('app://self/null-view');
        $ro->body = ['time' => '0'];
        $this->repository->put($ro);
        $this->assertIsString($ro->view);
    }

    public function testSerializable(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new QueryRepositoryModule(new ResourceModule($namespace));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(CacheItemPoolInterface::class)->annotatedWith(Shared::class)->to(FilesystemAdapter::class);
                $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->to(FilesystemAdapter::class);
            }
        });
        $injector = new Injector($module);
        serialize($injector);

        $repository = (new Injector($module))->getInstance(QueryRepositoryInterface::class);
        $unserilizedRepository = unserialize(serialize(unserialize(serialize($repository))));
        $this->assertInstanceOf(Repository::class, $unserilizedRepository);
    }

    public function testStateWithoutStoredAtDerivesAgeFromLastModified(): void
    {
        // A warm cache surviving a deploy holds entries saved before storedAt existed.
        // Their residence time is unknown, so Age falls back to the content's change time,
        // which over-states residence rather than presenting a cache hit as fresh.
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(ResourceStorageInterface::class)->toInstance(new FakeLegacyStateStorage());
            }
        });
        $repository = (new Injector($module, __DIR__ . '/tmp'))->getInstance(QueryRepositoryInterface::class);

        $state = $repository->get(new Uri('app://self/user?id=1'));

        $this->assertInstanceOf(ResourceState::class, $state);
        $age = (int) $state->headers[Header::AGE];
        $this->assertGreaterThanOrEqual(FakeLegacyStateStorage::RESIDENCE, $age);
        $this->assertLessThanOrEqual(FakeLegacyStateStorage::RESIDENCE + 1, $age);
    }

    public function testUnstorableMarkSkipsStoreAndOrdinaryResponsesStillStore(): void
    {
        // Ordinary (unmarked) responses store and hit normally - checked first, since the
        // mark is per-request state (here: one shared container for the whole test) that
        // cannot be unset once a later call sets it.
        $ordinary = $this->resource->get('app://self/unstorable-value', ['unstorable' => false]);
        $this->assertArrayNotHasKey(Header::PRAGMA, $ordinary->headers);
        $this->assertNotSame('private, no-store', $ordinary->headers[Header::CACHE_CONTROL]);
        $this->logger->flush();
        $this->resource->get('app://self/unstorable-value', ['unstorable' => false]);
        $treeOrdinary = $this->flushAndValidate($this->logger);
        $this->assertContains('cache_hit', self::collectTypes($treeOrdinary));

        // A producer (a CSRF token issuer) marks this request's response unstorable: the
        // write is skipped, the live response is still served, and its headers tell every
        // cache - shared or private - not to keep it (issue #222).
        $ro = $this->resource->get('app://self/unstorable-value');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);
        $this->assertSame('no-cache', $ro->headers[Header::PRAGMA]);
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);

        $tree = $this->flushAndValidate($this->logger);
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped);
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped);

        // Nothing was stored: a second GET of the same (still marked) request misses again.
        $this->logger->flush();
        $this->resource->get('app://self/unstorable-value');
        $tree2 = $this->flushAndValidate($this->logger);
        $this->assertContains('cache_miss', self::collectTypes($tree2));
        $this->assertNotContains('cache_hit', self::collectTypes($tree2));
    }

    public function testCacheableWithNoHttpCacheStillStores(): void
    {
        // #[NoHttpCache] declares its own Cache-Control (private, no-store, no-cache,
        // must-revalidate) - a header that equals the declared value is the attribute
        // speaking, not a per-request value, and must not trip the gate (issue #222
        // regression guard: 1.x stored this).
        $ro = $this->resource->get('app://self/cacheable-no-http-cache');
        $this->assertStringContainsString('max-age=10', $ro->headers[Header::CACHE_CONTROL]);
        $this->assertArrayNotHasKey(Header::PRAGMA, $ro->headers, 'the gate must not override #[NoHttpCache]\'s own headers');

        $this->logger->flush();
        $this->resource->get('app://self/cacheable-no-http-cache');
        $tree = $this->flushAndValidate($this->logger);
        $this->assertContains('cache_hit', self::collectTypes($tree), '#[NoHttpCache] must not disable the server-side store');
    }

    public function testAPreExistingMarkRefusesATopLevelCacheableResourcesOwnWriteEvenThoughItsOwnWriteWindowOpensAfterTheMark(): void
    {
        // #234 rejected option: a pure counter/snapshot-only predicate
        // (wasMarkedSinceCurrentWrite() ALONE) would miss this - CacheInterceptor's own
        // enterWrite() opens AFTER this mark, so the counter alone would read "nothing marked
        // since I opened". isMarkedForCurrentScope() is what still refuses it: the mark and
        // this top-level resource share the same (TOP_LEVEL) scope. Donut-side counterpart:
        // DonutRepositoryTest::testRefreshSkipsSavingContentAndCdnHeaderWhenMarkedUnstorable()
        // proves the same property on refreshDonut()'s (unchanged, any-owner) path; this is
        // QueryRepository::doPut()'s own, newly-scoped one.
        $this->mark->mark('pre-existing');

        $ro = $this->resource->get('app://self/value');
        $this->assertSame('private, no-store', $ro->headers[Header::CACHE_CONTROL]);

        $tree = $this->flushAndValidate($this->logger);
        $putSkipped = self::eventContextJsonOf($tree, 'put_skipped');
        $this->assertNotNull($putSkipped);
        $this->assertStringContainsString('"reason":"unstorable"', $putSkipped);
    }

    public function testADescendantCacheableResourceCalledSynchronouslyAfterAnAncestorsMarkIsAlsoRefused(): void
    {
        // #234 rejected option: wasMarkedSinceCurrentWrite() ALONE misses this - the
        // descendant's own write window (CacheInterceptor's enterWrite()) opens AFTER the
        // ancestor already marked, so the counter alone reads "nothing new". Only
        // isMarkedForCurrentScope() catches it: the descendant (InnocentCacheableSibling,
        // called synchronously from inside MarksThenCallsCacheableSibling's own onGet(),
        // right after its own mark()) shares the still-open marking ancestor's own scope.
        $this->resource->get('app://self/marks-then-calls-cacheable-sibling');

        $tree = $this->flushAndValidate($this->logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'put_skipped'), 'sanity: the ancestor refuses its own write, as before #234');

        // The real assertion: the DESCENDANT's own entry was never stored either - a direct
        // GET of its own URI is a fresh MISS, not a hit serving the leaked content.
        $this->logger->flush();
        $this->resource->get('app://self/innocent-cacheable-sibling');
        $tree2 = $this->flushAndValidate($this->logger);
        $this->assertContains('cache_miss', self::collectTypes($tree2), 'the descendant, called synchronously right after the ancestor\'s own mark, must also be refused');
        $this->assertNotContains('cache_hit', self::collectTypes($tree2));
    }
}
