<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\BodySerializationNotAllowedException;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use RuntimeException;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Throwable;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function dirname;
use function restore_error_handler;
use function set_error_handler;
use function usleep;

use const E_USER_WARNING;

/** Coverage for a #[Cacheable] body serialize() refuses, routed through BodySerializationErrorHandlerInterface. */
class BodySerializationErrorTest extends TestCase
{
    private const URI = 'app://self/unserializable-value';

    private function resourceFor(AbstractModule $module): ResourceInterface
    {
        return (new Injector($module, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
    }

    /** A production-shaped module: Skip bound, so a misclassified failure would be swallowed. */
    private function moduleWithSkip(): AbstractModule
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class extends AbstractModule {
            #[Override]
            protected function configure(): void
            {
                $this->bind(BodySerializationErrorHandlerInterface::class)->to(SkipSerializationError::class);
            }
        });

        return $module;
    }

    public function testProductionModuleCompositionAnswersAnUnserializableBodyUncachedWithPutSkipped(): void
    {
        // Through the real module composition an application installs - QueryRepositoryModule
        // wrapped by ProdQueryRepositoryModule, which binds SkipSerializationError over the
        // Throw default - not a toInstance() override of the interface alone.
        $logger = new RecordingSemanticLogger();
        $namespace = 'FakeVendor\HelloWorld';
        $module = new ProdQueryRepositoryModule(new QueryRepositoryModule(new ResourceModule($namespace)));
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(private readonly SemanticLoggerInterface $logger)
            {
            }

            #[Override]
            protected function configure(): void
            {
                // Swap the production pools (APCu/file-backed) and logger for fast, inspectable
                // test doubles; BodySerializationErrorHandlerInterface is left to the real modules.
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->to(ArrayAdapter::class);
                $this->bind(AdapterInterface::class)->annotatedWith(EtagPool::class)->to(ArrayAdapter::class);
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
            }
        });
        $resource = $this->resourceFor($module);

        $ro = $resource->get(self::URI);

        $this->assertSame(200, $ro->code);
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);

        $putSkipped = array_values(array_filter($logger->events, static fn ($event): bool => $event instanceof PutSkippedContext));
        $this->assertCount(1, $putSkipped);
        $this->assertSame('unserializable', $putSkipped[0]->reason);
    }

    public function testDefaultBindingThrowsWithTheUriAndChainsSerializesException(): void
    {
        $resource = $this->resourceFor(ModuleFactory::getInstance('FakeVendor\HelloWorld'));

        try {
            $resource->get(self::URI);
            $this->fail('expected BodySerializationNotAllowedException');
        } catch (BodySerializationNotAllowedException $e) {
            $this->assertSame(self::URI, $e->getMessage());
            $previous = $e->getPrevious();
            $this->assertNotNull($previous);
            $this->assertSame('Exception', $previous::class);
            $this->assertSame("Serialization of 'Closure' is not allowed", $previous->getMessage());
        }
    }

    public function testTheThrowPolicysCacheErrorNamesTheExceptionAndTheRefusedValue(): void
    {
        $logger = new RecordingSemanticLogger();
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(private readonly SemanticLoggerInterface $logger)
            {
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
            }
        });

        try {
            $this->resourceFor($module)->get(self::URI);
            $this->fail('expected BodySerializationNotAllowedException');
        } catch (BodySerializationNotAllowedException) {
        }

        $errors = array_values(array_filter($logger->events, static fn ($event): bool => $event instanceof CacheErrorContext));
        $this->assertCount(1, $errors);
        $this->assertSame(BodySerializationNotAllowedException::class, $errors[0]->exceptionClass);
        $this->assertSame("Serialization of 'Closure' is not allowed", $errors[0]->error);
    }

    public function testMobileEtagSetterRoutesTheSameFailureThroughTheHandler(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new MobileEtagModule());
        $resource = $this->resourceFor($module);

        $this->expectException(BodySerializationNotAllowedException::class);
        $resource->get(self::URI);
    }

    public function testSkipBindingServesTheResponseUncachedWithPutSkippedLogged(): void
    {
        $logger = new RecordingSemanticLogger();
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(private readonly SemanticLoggerInterface $logger)
            {
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
                $this->bind(BodySerializationErrorHandlerInterface::class)->to(SkipSerializationError::class);
            }
        });
        $resource = $this->resourceFor($module);

        $ro = $resource->get(self::URI);

        $this->assertSame(200, $ro->code);
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
        $this->assertArrayNotHasKey(Header::SURROGATE_KEY, $ro->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);

        $cacheControl = $ro->headers[Header::CACHE_CONTROL];
        $this->assertStringNotContainsString('s-maxage', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        // HttpCacheInterceptor writes #[HttpCache(maxAge: 60, sMaxAge: 600)] into Cache-Control
        // from inside the proceed() that CacheInterceptor's own repository->put() call (and so
        // HeaderSetter) follows - keeping max-age here proves the rewrite above runs after that
        // write and is the last word on the header, not an untouched attribute default.
        $this->assertStringContainsString('max-age=60', $cacheControl);

        $putSkipped = array_values(array_filter($logger->events, static fn ($event): bool => $event instanceof PutSkippedContext));
        $this->assertCount(1, $putSkipped);
        $this->assertSame(self::URI, $putSkipped[0]->uri);
        $this->assertSame('unserializable', $putSkipped[0]->reason);
        $this->assertSame('Closure', $putSkipped[0]->class);

        // Not stored server-side: a second GET re-runs onGet() instead of answering a pool hit.
        $second = $resource->get(self::URI);
        $this->assertArrayNotHasKey('Age', $second->headers, 'a cache hit would carry Age; onGet() ran again instead');
    }

    public function testDonutPageUnderMobileEtagAndSkipIsNotStoredDespiteARenderedView(): void
    {
        // MobileEtagSetter serializes the body unconditionally, even once the view is already
        // a rendered string (unlike EtagSetter's `$ro->view ?? ...` fallback), so a
        // #[CacheableResponse] page can hit this failure too (issue #259). DonutRepository's
        // doPutStatic()/setHeaders() must consume HeaderSetter's skip signal itself, not just
        // QueryRepository::doPut() - otherwise the page would still get stored despite the strip.
        $logger = new RecordingSemanticLogger();
        $namespace = 'FakeVendor\HelloWorld';
        $templates = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module = ModuleFactory::getInstance($namespace);
        $module->override(new MobileEtagModule());
        $module->override(new TwigModule([$templates]));
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(private readonly SemanticLoggerInterface $logger)
            {
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
                $this->bind(BodySerializationErrorHandlerInterface::class)->to(SkipSerializationError::class);
            }
        });
        $resource = $this->resourceFor($module);

        $ro = $resource->get('page://self/html/unserializable-donut');

        $this->assertSame(200, $ro->code);
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
        $this->assertArrayNotHasKey(Header::SURROGATE_KEY, $ro->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);

        $putSkipped = array_values(array_filter($logger->events, static fn ($event): bool => $event instanceof PutSkippedContext));
        $this->assertCount(1, $putSkipped);
        $this->assertSame('unserializable', $putSkipped[0]->reason);

        // Not stored server-side: a second GET misses the donut template (no Age) instead of
        // recomposing from a saved one.
        $second = $resource->get('page://self/html/unserializable-donut');
        $this->assertArrayNotHasKey('Age', $second->headers, 'a cache hit would carry Age; the template was never saved');
    }

    public function testARefreshUnderMobileEtagAndSkipDoesNotStoreTheRecomposedView(): void
    {
        $logger = new RecordingSemanticLogger();
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new MobileEtagModule());
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(private readonly SemanticLoggerInterface $logger)
            {
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
                $this->bind(BodySerializationErrorHandlerInterface::class)->to(SkipSerializationError::class);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $uri = 'page://self/html/unserializable-donut';

        $stored = $resource->newInstance($uri);
        $stored->body = ['name' => 'stored'];
        $donutRepository->putStatic($stored, 60, 1);
        usleep(1_100_000); // the view expires, the template stays
        $eventsBefore = count($logger->events);

        $refreshing = $resource->newInstance($uri);
        $refreshing->body = ['handler' => static fn () => null];
        $ro = $donutRepository->get($refreshing);

        $this->assertNotNull($ro);
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
        $this->assertArrayNotHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $putSkipped = array_values(array_filter(array_slice($logger->events, $eventsBefore), static fn ($event): bool => $event instanceof PutSkippedContext));
        $this->assertCount(1, $putSkipped);
        $this->assertSame('unserializable', $putSkipped[0]->reason);
        $this->assertNull($injector->getInstance(QueryRepositoryInterface::class)->get($refreshing->uri), 'the recomposed view is not stored');
    }

    public function testACustomHandlerThatReturnsGetsTheSkipSafetyBehaviourForFree(): void
    {
        $reporter = new class implements BodySerializationErrorHandlerInterface {
            public ResourceObject|null $ro = null;
            public Throwable|null $e = null;

            #[Override]
            public function __invoke(ResourceObject $ro, Throwable $e): void
            {
                // Stands in for reporting to an external service (Sentry, Datadog, ...): records
                // the call and returns without throwing, like SkipSerializationError.
                $this->ro = $ro;
                $this->e = $e;
            }
        };

        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class ($reporter) extends AbstractModule {
            public function __construct(private readonly BodySerializationErrorHandlerInterface $handler)
            {
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(BodySerializationErrorHandlerInterface::class)->toInstance($this->handler);
            }
        });
        $resource = $this->resourceFor($module);

        $ro = $resource->get(self::URI);

        $this->assertNotNull($reporter->ro);
        $this->assertSame(self::URI, (string) $reporter->ro->uri, 'the handler receives the ResourceObject');
        $this->assertNotNull($reporter->e);
        $this->assertSame('Exception', $reporter->e::class, 'the exact built-in class serialize() throws');
        $this->assertStringContainsString("Serialization of 'Closure' is not allowed", $reporter->e->getMessage(), 'the handler receives the serialize() exception');

        // The handler itself did none of this - HeaderSetter applies it unconditionally
        // whenever the handler returns, for every implementation.
        $this->assertSame(200, $ro->code);
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
        $this->assertArrayNotHasKey(Header::SURROGATE_KEY, $ro->headers);
        $cacheControl = $ro->headers[Header::CACHE_CONTROL];
        $this->assertStringNotContainsString('s-maxage', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function testSerializeThrowingAPlainExceptionPropagatesEvenUnderSkip(): void
    {
        // __serialize() throwing the same built-in Exception class serialize() raises for a
        // refusal is not a refusal: only the "Serialization of 'X' is not allowed" wording is.
        $resource = $this->resourceFor($this->moduleWithSkip());

        try {
            $resource->get('app://self/serialize-throws-plain-exception');
            $this->fail("expected the object's __serialize() exception to propagate");
        } catch (Throwable $e) {
            $this->assertSame('Exception', $e::class);
            $this->assertSame('__serialize failure, not a serialization refusal', $e->getMessage());
        }
    }

    public function testADomRefusalWithItsTrailingClauseIsHandledUnderSkip(): void
    {
        $logger = new RecordingSemanticLogger();
        $module = $this->moduleWithSkip();
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(private readonly SemanticLoggerInterface $logger)
            {
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
            }
        });

        $ro = $this->resourceFor($module)->get('app://self/unserializable-dom-value');

        $this->assertSame(200, $ro->code);
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
        $putSkipped = array_values(array_filter($logger->events, static fn ($event): bool => $event instanceof PutSkippedContext));
        $this->assertCount(1, $putSkipped);
        $this->assertSame('DOMDocument', $putSkipped[0]->class);
    }

    public function testSleepThrowingAPlainExceptionPropagatesEvenUnderSkip(): void
    {
        $resource = $this->resourceFor($this->moduleWithSkip());

        try {
            $resource->get('app://self/sleep-throws-plain-exception');
            $this->fail("expected the object's __sleep() exception to propagate");
        } catch (Throwable $e) {
            $this->assertSame('Exception', $e::class);
            $this->assertSame('__sleep failure, not a serialization refusal', $e->getMessage());
        }
    }

    public function testSerializeThrowingASubclassPropagatesEvenUnderSkip(): void
    {
        $resource = $this->resourceFor($this->moduleWithSkip());

        try {
            $resource->get('app://self/serialize-throws-runtime-exception');
            $this->fail("expected the object's RuntimeException to propagate");
        } catch (Throwable $e) {
            $this->assertSame(RuntimeException::class, $e::class);
        }
    }

    public function testAGrandchildOnGetPlainExceptionPropagatesEvenUnderSkip(): void
    {
        // The grandchild is a lazy, un-run Request nested inside the child's body. Its first
        // run, forced by setCacheDependency() stringifying the child, is swallowed by
        // ResourceObject::__toString()'s own safety net (trigger_error, returns ''), so
        // evaluateBody() runs it again inside EtagSetter's call; that second throw is the one
        // that must reach HeaderSetter without being mistaken for a refusal.
        $resource = $this->resourceFor($this->moduleWithSkip());

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $resource->get('app://self/plain-exception-parent');
            $this->fail("expected the grandchild's onGet() exception to propagate");
        } catch (Throwable $e) {
            $this->assertSame('Exception', $e::class);
            $this->assertSame('grand boom', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $warnings, "the grandchild's first, swallowed attempt");
        $this->assertStringContainsString('grand boom', $warnings[0]);
    }
}
