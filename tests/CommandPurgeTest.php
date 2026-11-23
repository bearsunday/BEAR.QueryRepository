<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\RefreshNotResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * #[Purge] on a class that stores nothing: the write invalidates its own key together with what the
 * annotations name, and reads nothing again
 */
class CommandPurgeTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(ModuleFactory::getInstance($namespace), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

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

        // Self-targeting #[Purge]: the write's own key, which it invalidates anyway.
        $this->assertNull($this->repository->get($selfUri));
        $this->assertNull($this->storage->validatedEntry($selfEtag));

        // Dependent #[Purge]: purging the embedded child invalidates the parent that embeds it.
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-two')));
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-one')));
        $this->assertNull($this->storage->validatedEntry($levelOneEtag));

        $tree = $this->flushAndValidate($this->logger);

        $command = self::contextJsonOf($tree, 'command');
        $this->assertNotNull($command, 'the write opens a command scope');
        $this->assertStringContainsString('"method":"onPut"', $command);
        $this->assertStringContainsString('"uri":"page://self/dep/purge-src{?id}"', $command);
        $this->assertStringContainsString('"uri":"page://self/dep/level-two"', $command);

        $close = self::closeContextJsonOf($tree, 'command_result');
        $this->assertNotNull($close);
        $this->assertStringContainsString('"code":200', $close);

        // One purge per URI the write names, its own key and the #[Purge] of itself being one, each immediately
        // followed by its invalidation and by no pre_write_cleanup marker: these delete entries, they do not precede a
        // rewrite.
        $this->assertContains(
            ['purge', 'invalidate', 'purge', 'invalidate'],
            self::scopeEventTypeSequences($tree),
            'the command scope purges its own key once, then level-two',
        );
    }

    public function testACommandThatDoesNotReturnItsResourceIsNamed(): void
    {
        // The interceptor has to refresh something and was handed a string. Continuing would
        // refresh whatever the URI template resolved against a resource that no longer exists in
        // this scope, so the class name says what happened and the write stops here.
        $this->expectException(ReturnValueIsNotResourceObjectException::class);
        $this->expectExceptionMessage(RefreshNotResourceObject::class);

        $this->resource->put('app://self/refresh-not-resource-object', []);
    }
}
