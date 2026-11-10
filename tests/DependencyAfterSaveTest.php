<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class DependencyAfterSaveTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $this->boot(false);

        parent::setUp();
    }

    private function boot(bool $refuseEntries): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        if ($refuseEntries) {
            $module->override(new class extends AbstractModule {
                #[Override]
                protected function configure(): void
                {
                    $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter())));
                    $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toInstance(new TagAwareAdapter(new ArrayAdapter()));
                }
            });
        }

        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->logger->flush();
    }

    public function testADependencyAValuePageDeclaresWhileRenderingIsLogged(): void
    {
        $this->resource->get('page://self/html/render-time-dependency-value')->toString();
        $tree = $this->flushAndValidate($this->logger);

        $contexts = self::eventContextsJsonOf($tree, 'dependency_after_save');
        $this->assertCount(1, $contexts);
        $this->assertSame([
            'parent' => 'page://self/html/render-time-dependency-value',
            'child' => 'page://self/html/mutable-comment',
        ], json_decode($contexts[0], true, 512, JSON_THROW_ON_ERROR));
        $this->assertCount(1, self::eventContextsJsonOf($tree, 'depends_on'));
    }

    public function testADependencyAValuePageServedFromTheCacheDeclaresWhileRenderingIsLogged(): void
    {
        $this->resource->get('page://self/html/render-time-dependency-value')->toString();
        $this->logger->flush();

        $this->resource->get('page://self/html/render-time-dependency-value')->toString();
        $tree = $this->flushAndValidate($this->logger);

        $this->assertCount(1, self::eventContextsJsonOf($tree, 'dependency_after_save'));
    }

    /** @return array<string, array{string}> */
    public static function storedWithItsDependencyProvider(): array
    {
        return [
            'a view page rendering during its put' => ['page://self/html/render-time-dependency-view'],
            'a donut page rendering during its put' => ['page://self/html/render-time-dependency'],
            'a value page embedding its child' => ['page://self/dep/parent-a'],
            'a value page that sets its own ETag in onGet()' => ['page://self/dep/self-etag-parent'],
            'a view page declaring in onGet()' => ['page://self/dep/depending-in-on-get-view'],
        ];
    }

    #[DataProvider('storedWithItsDependencyProvider')]
    public function testADependencyDeclaredBeforeTheSaveIsNotLogged(string $uri): void
    {
        $this->resource->get($uri)->toString();
        $tree = $this->flushAndValidate($this->logger);

        $this->assertNotSame([], self::eventContextsJsonOf($tree, 'depends_on'));
        $this->assertSame([], self::eventContextsJsonOf($tree, 'dependency_after_save'));
    }

    public function testARefreshThatPutsThePageAgainIsNotLogged(): void
    {
        $this->boot(true);
        $this->resource->put('page://self/dep/refresh-parent-a');
        $tree = $this->flushAndValidate($this->logger);

        $this->assertCount(2, self::eventContextsJsonOf($tree, 'depends_on', null), 'the GET stores the page, the pool refuses it, and the refresh puts it again');
        $this->assertSame([], self::eventContextsJsonOf($tree, 'dependency_after_save'));
    }

    public function testAManualPutOfAPageAlreadyStoredIsNotLogged(): void
    {
        $ro = $this->resource->get('page://self/dep/parent-a');
        $this->logger->flush();

        $this->repository->put($ro);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertCount(1, self::eventContextsJsonOf($tree, 'depends_on'));
        $this->assertSame([], self::eventContextsJsonOf($tree, 'dependency_after_save'));
    }
}
