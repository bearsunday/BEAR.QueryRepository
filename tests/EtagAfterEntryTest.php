<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\RefreshDonutContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function array_filter;
use function dirname;

/** A conditional request is answered 304 only for a response the pool holds (#261) */
class EtagAfterEntryTest extends TestCase
{
    private function app(bool $refuseEntries, string $refusedKeyPrefix = '', SemanticLoggerInterface|null $logger = null): Injector
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if ($refuseEntries) {
            $module->override(new class ($refusedKeyPrefix) extends AbstractModule {
                public function __construct(private readonly string $refusedKeyPrefix)
                {
                    parent::__construct();
                }

                #[Override]
                protected function configure(): void
                {
                    $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refusedKeyPrefix: $this->refusedKeyPrefix);
                    $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($pool);
                }
            });
        }

        if ($logger !== null) {
            $module->override(new class ($logger) extends AbstractModule {
                public function __construct(private readonly SemanticLoggerInterface $logger)
                {
                    parent::__construct();
                }

                #[Override]
                protected function configure(): void
                {
                    $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
                }
            });
        }

        return new Injector($module, __DIR__ . '/tmp');
    }

    /** Would a client revalidating with this response's ETag be answered 304? */
    private function answers304(Injector $injector, ResourceObject $ro): bool
    {
        $this->assertArrayHasKey(Header::ETAG, $ro->headers);

        return $injector->getInstance(HttpCacheInterface::class)->isNotModified([Header::HTTP_IF_NONE_MATCH => $ro->headers[Header::ETAG]]);
    }

    public function testAValueEntryThePoolRefusesIsNotAnswered304(): void
    {
        $injector = $this->app(refuseEntries: true);

        $ro = $injector->getInstance(ResourceInterface::class)->get('app://self/user', ['id' => 1]);

        $this->assertFalse($this->answers304($injector, $ro));
    }

    public function testADonutPageViewThePoolRefusesIsNotAnswered304(): void
    {
        $injector = $this->app(refuseEntries: true);

        $ro = $injector->getInstance(ResourceInterface::class)->get('page://self/html/blog-posting', ['id' => 0]);

        $this->assertFalse($this->answers304($injector, $ro));
    }

    public function testADonutPageRecomposedFromItsTemplateWhoseViewThePoolRefusesIsNotAnswered304(): void
    {
        // `ro-` is the page view's key: the template is stored, the view never is, so the second
        // read recomposes the page from its template and saves the view again through saveView().
        $logger = new RecordingSemanticLogger();
        $injector = $this->app(refuseEntries: true, refusedKeyPrefix: 'ro-', logger: $logger);
        $resource = $injector->getInstance(ResourceInterface::class);
        $resource->get('page://self/html/blog-posting', ['id' => 0]);

        $recomposed = $resource->get('page://self/html/blog-posting', ['id' => 0]);

        $refreshes = array_filter($logger->events, static fn (object $event): bool => $event instanceof RefreshDonutContext);
        $this->assertCount(1, $refreshes, 'the second read recomposes from the stored template');
        $this->assertFalse($this->answers304($injector, $recomposed));
    }

    public function testAStoredEntryIsAnswered304(): void
    {
        $injector = $this->app(refuseEntries: false);
        $resource = $injector->getInstance(ResourceInterface::class);

        $value = $resource->get('app://self/user', ['id' => 1]);
        $page = $resource->get('page://self/html/blog-posting', ['id' => 0]);

        $this->assertTrue($this->answers304($injector, $value));
        $this->assertTrue($this->answers304($injector, $page));
    }

    public function testAnEntryWithoutAValidatorIsStoredWithoutOne(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class extends AbstractModule {
            #[Override]
            protected function configure(): void
            {
                $this->bind(EtagSetterInterface::class)->toInstance(new class implements EtagSetterInterface {
                    #[Override]
                    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null): void
                    {
                    }
                });
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');

        $ro = $injector->getInstance(ResourceInterface::class)->get('app://self/user', ['id' => 1]);

        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
        $this->assertNotNull($injector->getInstance(ResourceStorageInterface::class)->get(new Uri('app://self/user?id=1')));
    }
}
