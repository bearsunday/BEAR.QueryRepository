<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Html\MutableComment;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\Scope;

use function assert;
use function dirname;
use function strtotime;
use function time;

class DonutQueryInterceptorPurgeTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepository $repository;
    private ResourceStorageInterface $storage;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(EtagSetterInterface::class)->to(AdvancingEtagSetter::class)->in(Scope::SINGLETON);
            }
        });
        if (! $injector) {
            $injector = new Injector($module, __DIR__ . '/tmp');
        }

        assert($injector instanceof Injector);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepository::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        MutableComment::$comment = 'comment-a';
        // Every emitted log entry must conform to the published schema (drift detection)
        $this->flushAndValidate($this->logger);
    }

    public function testStatePurge(): void
    {
        $ro1 = $this->resource->get('page://self/html/blog-posting');
        $this->assertSame(200, $ro1->code);
        $this->assertFalse($this->wasRecomposedFromDonut(), 'the first access creates the donut, it does not refresh one');
        $this->assertTrue($this->isStateCached());
        $purgeResult = $this->repository->purge(new Uri('page://self/html/comment'));
        assert($purgeResult);
        $this->assertFalse($this->isStateCached());

        $this->resource->get('page://self/html/blog-posting');
        $this->assertTrue($this->wasRecomposedFromDonut());
        $this->assertTrue($this->isStateCached(), 'Resource state should be cached');
    }

    public function testRefreshKeepsLastModifiedWhenContentUnchanged(): void
    {
        $ro1 = $this->resource->get('page://self/html/blog-posting');
        $lastModified = $ro1->headers[Header::LAST_MODIFIED];
        $purgeResult = $this->repository->purge(new Uri('page://self/html/comment'));
        assert($purgeResult);

        $ro2 = $this->resource->get('page://self/html/blog-posting');
        $this->assertTrue($this->wasRecomposedFromDonut(), 'donut refresh should have run');
        $this->assertSame($lastModified, $ro2->headers[Header::LAST_MODIFIED], 'Last-Modified should be carried over when the recomposed content is identical');
    }

    public function testUnchangedRecompositionKeepsTheEntityTag(): void
    {
        $ro1 = $this->resource->get('page://self/html/blog-posting');
        $etag = $ro1->headers[Header::ETAG];
        $purgeResult = $this->repository->purge(new Uri('page://self/html/comment'));
        assert($purgeResult);

        $ro2 = $this->resource->get('page://self/html/blog-posting');
        $this->assertTrue($this->wasRecomposedFromDonut(), 'donut refresh should have run');
        // The entity-tag validates the representation, so a recomposition that yields
        // identical content must not change it — otherwise every refresh turns a client's
        // conditional request into a full 200 for content it already holds.
        $this->assertSame($etag, $ro2->headers[Header::ETAG]);
        $this->assertTrue(
            (new HttpCache($this->storage))->isNotModified([Header::HTTP_IF_NONE_MATCH => $etag]),
            'the pre-refresh validator still matches the stored ETag (304)',
        );
    }

    public function testAgeIsResidenceTimeSinceStored(): void
    {
        $ro1 = $this->resource->get('page://self/html/blog-posting');
        $lastModified = strtotime($ro1->headers[Header::LAST_MODIFIED]);
        $purgeResult = $this->repository->purge(new Uri('page://self/html/comment'));
        assert($purgeResult);
        $this->resource->get('page://self/html/blog-posting'); // refresh carries over the old Last-Modified

        $state = $this->repository->get(new Uri('page://self/html/blog-posting'));
        assert($state instanceof ResourceState);
        $this->assertLessThanOrEqual(1, (int) $state->headers[Header::AGE], 'Age is the time since the state was stored, not since Last-Modified');
        $this->assertGreaterThanOrEqual(1, time() - $lastModified);
    }

    public function testRefreshPersistsChangedContentState(): void
    {
        $pageUri = 'page://self/html/mutable-blog-posting';
        $commentUri = new Uri('page://self/html/mutable-comment');
        MutableComment::$comment = 'comment-a';
        $a1 = $this->resource->get($pageUri);
        $aLastModified = $a1->headers[Header::LAST_MODIFIED];
        $this->assertStringContainsString('comment-a', (string) $a1->view);
        $this->flushAndValidate($this->logger); // drain the initial put

        MutableComment::$comment = 'comment-b';
        $this->repository->purge($commentUri);
        $b1 = $this->resource->get($pageUri);
        $bLastModified = $b1->headers[Header::LAST_MODIFIED];
        $this->assertNotSame($aLastModified, $bLastModified, 'A→B advances Last-Modified');
        $this->assertStringContainsString('comment-b', (string) $b1->view);
        $this->assertNotSame($a1->headers[Header::ETAG], $b1->headers[Header::ETAG], 'changed content changes the validator');
        $this->assertFalse($this->storage->hasEtag($a1->headers[Header::ETAG]), 'the superseded validator leaves the pool, so a client holding it gets 200');
        $changedTree = $this->flushAndValidate($this->logger);
        $saveDonut = self::eventContextJsonOf($changedTree, 'save_donut');
        $this->assertNotNull($saveDonut, 'changed content persists the new donut comparison state');
        $this->assertStringContainsString('"mutable-blog-posting-page"', $saveDonut, 'the original storage tags are reused');

        $this->repository->purge($commentUri);
        $b2 = $this->resource->get($pageUri);
        $this->assertSame($bLastModified, $b2->headers[Header::LAST_MODIFIED], 'B→B keeps the B change time');
        $this->assertSame($b1->headers[Header::ETAG], $b2->headers[Header::ETAG], 'B→B keeps the validator, so the client still revalidates to 304');
        $unchangedTree = $this->flushAndValidate($this->logger);
        $this->assertNull(self::eventContextJsonOf($unchangedTree, 'save_donut'), 'unchanged content does not rewrite the donut');

        MutableComment::$comment = 'comment-a';
        $this->repository->purge($commentUri);
        $a2 = $this->resource->get($pageUri);
        $this->assertNotSame($aLastModified, $a2->headers[Header::LAST_MODIFIED], 'A→B→A does not revive the old A time');
        $this->assertNotSame($bLastModified, $a2->headers[Header::LAST_MODIFIED], 'B→A advances Last-Modified');
    }

    private function isStateCached(): bool
    {
        return $this->repository->get(new Uri('page://self/html/blog-posting')) instanceof ResourceState;
    }

    /**
     * Whether the page just fetched was recomposed from the cached donut
     *
     * Reads the fact from the semantic log (`refresh_donut`), which is where the
     * cache records it; the response itself carries no refresh marker — the ETag
     * stays a pure validator of the representation.
     */
    private function wasRecomposedFromDonut(): bool
    {
        $tree = $this->flushAndValidate($this->logger);

        return self::eventContextJsonOf($tree, 'refresh_donut') !== null;
    }
}
