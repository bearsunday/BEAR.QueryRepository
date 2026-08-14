<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * RefreshInterceptor's #[Purge] branch, which no #[Cacheable] fixture can reach
 *
 * Every other #[Purge] fixture is #[Cacheable], so its annotation is processed by
 * CommandInterceptor alongside RefreshSameCommand. Here the class is not #[Cacheable]:
 * RefreshInterceptor is the only interceptor bound, RefreshAnnotatedCommand the only
 * command, and nothing purges the written resource implicitly.
 */
class RefreshInterceptorPurgeTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class);

        parent::setUp();
    }

    public function testPurgeOnNonCacheableClassInvalidatesItselfAndCascadesToParent(): void
    {
        // Seed the dependency chain the write will bust: level-one embeds level-two.
        $this->resource->get('page://self/dep/level-one');
        $levelOne = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $levelOne);
        $levelOneEtag = $levelOne->headers[Header::ETAG];

        // PurgeSrc is not #[Cacheable], so no CacheInterceptor stores it: put its
        // representation by hand, the way an application caches a resource it manages itself.
        $src = $this->resource->get('page://self/dep/purge-src', ['id' => '1']);
        $this->repository->put($src);
        $selfUri = new Uri('page://self/dep/purge-src?id=1');
        $stored = $this->repository->get($selfUri);
        $this->assertInstanceOf(ResourceState::class, $stored);
        $selfEtag = $stored->headers[Header::ETAG];

        $this->resource->put('page://self/dep/purge-src', ['id' => '1']);

        // Self-targeting #[Purge]: on a #[Cacheable] class RefreshSameCommand would have
        // done this, here the annotation is the only thing that does.
        $this->assertNull($this->repository->get($selfUri));
        $this->assertFalse($this->storage->hasEtag($selfEtag));

        // Dependent #[Purge]: purging the embedded child invalidates the parent that embeds it.
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-two')));
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-one')));
        $this->assertFalse($this->storage->hasEtag($levelOneEtag));

        $tree = $this->flushAndValidate($this->logger);

        $command = self::contextJsonOf($tree, 'command');
        $this->assertNotNull($command, 'RefreshInterceptor opens a command scope');
        $this->assertStringContainsString('"source":"RefreshInterceptor"', $command);
        $this->assertStringContainsString('"method":"onPut"', $command);
        $this->assertStringContainsString('"uri":"page://self/dep/purge-src{?id}"', $command);
        $this->assertStringContainsString('"uri":"page://self/dep/level-two"', $command);

        $close = self::closeContextJsonOf($tree, 'command_result');
        $this->assertNotNull($close);
        $this->assertStringContainsString('"code":200', $close);

        // One purge event per annotation, each immediately followed by its invalidation and
        // by no pre_write_cleanup marker: these delete entries, they do not precede a rewrite.
        $this->assertContains(
            ['purge', 'invalidate', 'purge', 'invalidate'],
            self::scopeEventTypeSequences($tree),
            'the command scope holds both annotation-driven purges in declaration order',
        );
    }
}
