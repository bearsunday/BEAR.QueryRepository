<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/**
 * Logging must never break cache behavior
 *
 * Since koriym/semantic-logger 0.9 the core logger is a total function: protocol
 * misuse (a LIFO violation, a session left unclosed) never throws — it is recorded
 * in-band as `semantic_logger_error` diagnostics. A broken logging session can
 * therefore no longer break cache reads/writes (and the dependency cascade).
 */
class GracefulLoggingTest extends TestCase
{
    use SemanticLogTreeTrait;

    public function testCacheWorksWhenLoggingSessionIsMisused(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // Misuse the shared logger between cache operations: open two scopes and
        // close the outer one first — a LIFO violation.
        $outer = $logger->open(new GetContext('page://self/misuse/outer'));
        $logger->open(new GetContext('page://self/misuse/inner'));
        $logger->close(new CacheMissContext('resource'), $outer);

        // Building the 3-level dependency chain must succeed despite the broken session.
        $ro = $resource->get('page://self/dep/level-one');
        $this->assertSame(200, $ro->code);
        $this->assertArrayHasKey(Header::ETAG, $ro->headers);
        $this->assertArrayNotHasKey(Header::AGE, $ro->headers, 'first access is a miss (no Age header)');

        // A second access is served from cache: same stored ETag, an Age header proves the
        // stored entry was reused, and still no exception leaks out.
        $cached = $resource->get('page://self/dep/level-one');
        $this->assertSame($ro->headers[Header::ETAG], $cached->headers[Header::ETAG]);
        $this->assertArrayHasKey(Header::AGE, $cached->headers, 'second access is an observable cache hit');

        // The misuse did not vanish silently: it is recorded in-band as a diagnostic,
        // which validates against the core schemas bundled with koriym/semantic-logger.
        $tree = $this->flushAndValidateWithDiagnostics($logger);
        $diagnostic = self::eventContextJsonOf($tree, 'semantic_logger_error');
        $this->assertNotNull($diagnostic, 'the LIFO violation is recorded as a diagnostic');
        $this->assertStringContainsString('close_id_mismatch', $diagnostic);
    }

    public function testCacheErrorIsLoggedWhenCacheServerIsDown(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new TagAwareAdapter(new FakeErrorCache()));
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);

        // The cache pool is down: the read falls back to a live GET with a warning, not an exception.
        $warningCaught = false;
        set_error_handler(static function (int $errno) use (&$warningCaught): bool {
            if ($errno === E_USER_WARNING) {
                $warningCaught = true;

                return true; // swallow the cache-down warning
            }

            return false;
        });
        try {
            $ro = $resource->get('app://self/user', ['id' => 1]);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($warningCaught, 'the cache-down fallback warns (E_USER_WARNING) instead of throwing');
        $this->assertSame(200, $ro->code);

        $tree = $this->flushAndValidate($logger);
        // The outage is logged as cache_error, distinguishable from a cold miss...
        $error = self::eventContextJsonOf($tree, 'cache_error');
        $this->assertNotNull($error, 'a cache_error event marks the degraded cache layer');
        $this->assertStringContainsString('app://self/user', $error);
        $this->assertStringContainsString('"operation":"read"', $error, 'the failing side (the repository get) is recorded');
        $this->assertStringContainsString('cache server down', $error);
        // ...while the get scope still closes cache_miss: the pair (cache_error + cache_miss)
        // is an outage, a lone cache_miss is a cold cache.
        $close = self::closeContextJsonOf($tree, 'cache_miss');
        $this->assertNotNull($close, 'the get scope still closes cache_miss');
        $this->assertStringContainsString('"layer":"resource"', $close);
    }
}
