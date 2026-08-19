<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\PoolErrorLogger;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ray\Di\AbstractModule;
use Ray\Di\InjectionPoints;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * A store that cannot be reached, and what the log says about it
 *
 * `symfony/cache` adapters answer a read as a miss and a write as `false` rather than throwing,
 * so the `catch` that produces `cache_error` never runs: without the adapter's own report a dead
 * store is indistinguishable from a cold one. The pools are given the cache log so it is.
 *
 * A closed port on localhost is the cheapest real version of this: connection refused, no
 * timeout, no service to install.
 */
class PoolErrorLogTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $module = new class (ModuleFactory::getInstance('FakeVendor\HelloWorld')) extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(LoggerInterface::class)->annotatedWith('poolError')->to(PoolErrorLogger::class);
                // lazy: ext-redis connects eagerly otherwise, and the boot-time throw is not
                // the runtime outage this test simulates (Predis is lazy by default).
                $this->bind()->annotatedWith('deadRedis')->toInstance(RedisAdapter::createConnection('redis://127.0.0.1:1?lazy=1'));
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toConstructor(
                    RedisTagAwareAdapter::class,
                    ['redis' => 'deadRedis'],
                    (new InjectionPoints())->addMethod('setLogger', 'poolError'),
                );
            }
        };
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        parent::setUp();
    }

    public function testTheRequestIsServedAndTheStoreIsNamedAsWhatFailed(): void
    {
        $ro = $this->resource->get('app://self/value');

        $this->assertSame(200, $ro->code, 'a cache that cannot be reached costs latency, not the response');

        $tree = $this->flushAndValidate($this->logger);
        $types = self::collectTypes($tree);

        $this->assertContains('pool_error', $types, 'the store refused and the log has to say so');
        // The read is what a request hits first, and it is the half that has no other signal:
        // a write at least reports saved: false.
        $this->assertStringContainsString('"operation":"read"', (string) self::eventContextJsonOf($tree, 'pool_error'));
    }

    public function testTheBackendsOwnMessageIsCarried(): void
    {
        $this->resource->get('app://self/value');

        $context = (string) self::eventContextJsonOf($this->flushAndValidate($this->logger), 'pool_error');

        // The wording is the backend's own and differs by OS and client ("Connection refused"
        // on POSIX, "actively refused" on Windows): what the log owes is a non-empty message.
        $this->assertStringNotContainsString('"error":""', $context);
        $this->assertStringNotContainsString('"exceptionClass":"unknown"', $context, 'the throwable the adapter caught');
    }

    public function testAMissIsStillRecordedSoTheReadPathIsNotSilent(): void
    {
        // The miss is not a lie - there is no entry - but on its own it reads as cold. The pair is
        // the diagnosis: a miss beside a pool_error is a store that is down.
        $this->resource->get('app://self/value');

        $types = self::collectTypes($this->flushAndValidate($this->logger));

        $this->assertContains('cache_miss', $types);
        $this->assertContains('pool_error', $types);
    }

    public function testOnlyFailuresAreRecordedAndUnrecognizedWordingIsUnknown(): void
    {
        $logger = new SemanticLogger();
        $poolLogger = new PoolErrorLogger($logger);
        $openId = $logger->open(new GetContext('app://self/value'));
        $poolLogger->info('everything is fine');           // not a failure level: must not be recorded
        $poolLogger->error('something happened');          // a failure with no operation word in it
        $logger->close(new GetContext('app://self/value'), $openId);

        $tree = $this->flushAndValidate($logger);

        $this->assertSame(['get', 'pool_error', 'get'], self::collectTypes($tree));
        $this->assertStringContainsString('"operation":"unknown"', (string) self::eventContextJsonOf($tree, 'pool_error'));
    }
}
