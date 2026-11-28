<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\ManualInvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeResultContext;
use BEAR\QueryRepository\Log\Context\ManualStoreContext;
use BEAR\QueryRepository\Log\Context\ManualStoreResultContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function array_filter;
use function array_values;

/**
 * Manual-scope rooting is a TopLevelAwareInterface capability, not a SafeSemanticLogger privilege
 *
 * A custom logger that implements TopLevelAwareInterface (but is not
 * SafeSemanticLogger) must have its top-level direct put()/purge()/invalidateTags()
 * rooted in manual scopes; without the capability those operations would be
 * recorded as bare root-level events, indistinguishable from framework-driven ones.
 */
class TopLevelAwareLoggerTest extends TestCase
{
    private RecordingTopLevelAwareLogger $logger;
    private QueryRepositoryInterface $repository;
    private DonutRepositoryInterface $donutRepository;
    private ResourceStorageInterface $storage;
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $this->logger = new RecordingTopLevelAwareLogger();
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $logger = $this->logger;
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(
                private readonly RecordingTopLevelAwareLogger $logger,
            ) {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->donutRepository = $injector->getInstance(DonutRepositoryInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testTopLevelDirectCallsAreRootedInManualScopes(): void
    {
        $user = $this->resource->get('app://self/user', ['id' => 1]);

        // Direct top-level put(): rooted in manual_store with the stored outcome.
        $this->repository->put($user);
        $storeOpens = self::ofType($this->logger->opens, ManualStoreContext::class);
        $this->assertCount(1, $storeOpens, 'a top-level put is rooted for any TopLevelAware logger');
        $storeCloses = self::ofType($this->logger->closes, ManualStoreResultContext::class);
        $this->assertCount(1, $storeCloses);
        $this->assertTrue($storeCloses[0]->stored);

        // Direct top-level purge(): rooted in manual_purge with the purged outcome.
        $this->repository->purge($user->uri);
        $purgeOpens = self::ofType($this->logger->opens, ManualPurgeContext::class);
        $this->assertCount(1, $purgeOpens);
        $purgeCloses = self::ofType($this->logger->closes, ManualPurgeResultContext::class);
        $this->assertCount(1, $purgeCloses);
        $this->assertTrue($purgeCloses[0]->purged);

        // Direct top-level invalidateTags(): rooted in manual_invalidate.
        $this->storage->invalidateTags(['user_1']);
        $invalidateOpens = self::ofType($this->logger->opens, ManualInvalidateContext::class);
        $this->assertCount(1, $invalidateOpens);
    }

    public function testTopLevelDonutStoreReportsFailedWhenUnstorable(): void
    {
        $storable = $this->resource->get('app://self/user', ['id' => 1]);
        $this->donutRepository->putStatic($storable);

        $unstorable = clone $storable;
        $unstorable->headers[Header::CACHE_CONTROL] = 'private';
        $this->donutRepository->putStatic($unstorable);

        $storeCloses = self::ofType($this->logger->closes, ManualStoreResultContext::class);
        $this->assertCount(2, $storeCloses);
        $this->assertTrue($storeCloses[0]->stored);
        $this->assertFalse($storeCloses[1]->stored);
    }

    public function testTopLevelDonutStoreReportsFailedWhenPoolRefusesViewOrTemplate(): void
    {
        // Refusing only ro- makes saveDonutView() return false while saveDonut() still runs and succeeds
        [$viewRefusedLogger, $viewRefusedDonut, $viewRefusedStorage, $viewRefusedResource] = $this->envWithRefusingPool('ro-');
        $ro = $viewRefusedResource->get('app://self/user', ['id' => 1]);
        $viewRefusedDonut->putStatic($ro);
        $viewCloses = self::ofType($viewRefusedLogger->closes, ManualStoreResultContext::class);
        $this->assertCount(1, $viewCloses);
        $this->assertFalse($viewCloses[0]->stored);
        $this->assertNotNull($viewRefusedStorage->getDonut($ro->uri), 'template save still runs when view save is refused');

        // Refusing only donut- makes saveDonut() return false for both putStatic() and putDonut()
        [$donutRefusedLogger, $donutRefusedDonut, , $donutRefusedResource] = $this->envWithRefusingPool('donut-');
        $page = $donutRefusedResource->get('app://self/user', ['id' => 1]);
        $donutRefusedDonut->putStatic($page);
        $donutRefusedDonut->putDonut(clone $page, null);
        $donutCloses = self::ofType($donutRefusedLogger->closes, ManualStoreResultContext::class);
        $this->assertCount(2, $donutCloses);
        $this->assertFalse($donutCloses[0]->stored, 'putStatic() reports failed when template save is refused');
        $this->assertFalse($donutCloses[1]->stored, 'putDonut() reports failed when template save is refused');
    }

    /** @return array{RecordingTopLevelAwareLogger, DonutRepositoryInterface, ResourceStorageInterface, ResourceInterface} */
    private function envWithRefusingPool(string $refusedKeyPrefix): array
    {
        $logger = new RecordingTopLevelAwareLogger();
        $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refusedKeyPrefix: $refusedKeyPrefix);
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class ($logger, $pool) extends AbstractModule {
            public function __construct(
                private readonly RecordingTopLevelAwareLogger $logger,
                private readonly TagAwareAdapterInterface $pool,
            ) {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->annotatedWith(CacheLog::class)->toInstance($this->logger);
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($this->pool);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');

        return [
            $logger,
            $injector->getInstance(DonutRepositoryInterface::class),
            $injector->getInstance(ResourceStorageInterface::class),
            $injector->getInstance(ResourceInterface::class),
        ];
    }

    /**
     * @param list<object>    $contexts
     * @param class-string<T> $type
     *
     * @return list<T>
     *
     * @template T of object
     */
    private static function ofType(array $contexts, string $type): array
    {
        return array_values(array_filter($contexts, static fn (object $context): bool => $context instanceof $type));
    }
}
