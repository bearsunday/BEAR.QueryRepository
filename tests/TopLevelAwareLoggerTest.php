<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\ManualInvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeContext;
use BEAR\QueryRepository\Log\Context\ManualPurgeResultContext;
use BEAR\QueryRepository\Log\Context\ManualStoreContext;
use BEAR\QueryRepository\Log\Context\ManualStoreResultContext;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function array_filter;
use function array_values;

/**
 * Manual-scope rooting is a TopLevelAwareInterface capability, not a SafeSemanticLogger privilege
 *
 * A custom logger that implements TopLevelAwareInterface (but is not
 * SafeSemanticLogger) must have its top-level direct put()/purge()/invalidateTags()
 * rooted in manual scopes; without the capability those records would be
 * dropped at flush.
 */
class TopLevelAwareLoggerTest extends TestCase
{
    private RecordingTopLevelAwareLogger $logger;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $this->logger = new RecordingTopLevelAwareLogger();
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $logger = $this->logger;
        $module->override(new class ($logger) extends AbstractModule {
            public function __construct(
                private readonly RecordingTopLevelAwareLogger $logger,
            ) {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->bind(SemanticLoggerInterface::class)->toInstance($this->logger);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
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
