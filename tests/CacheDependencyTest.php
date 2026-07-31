<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\HalModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function explode;

class CacheDependencyTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)), __DIR__ . '/tmp');
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);

        parent::setUp();
    }

    public function testDestroyByChild(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $one1 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $one1);
        $etag1 = $one1->headers[Header::ETAG];
        // destroy by child
        $this->repository->purge(new Uri('page://self/dep/level-two'));
        $one2 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertNull($one2);
        $this->assertFalse($this->storage->hasEtag($etag1));
    }

    public function testDestroyByGrandChild(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $one1 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $one1);
        $this->repository->purge(new Uri('page://self/dep/level-three'));
        $one2 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertNull($one2);
        $etag1 = $one1->headers[Header::ETAG];
        $surrogateKeys = explode(' ', $one1->headers['Surrogate-Key']);
        $etag2 = $surrogateKeys[0];
        $etag3 = $surrogateKeys[1];
        $this->assertFalse($this->storage->hasEtag($etag1));
        $this->assertFalse($this->storage->hasEtag($etag2));
        $this->assertFalse($this->storage->hasEtag($etag3));
    }

    /**
     * The same grandchild cascade as testDestroyByGrandChild, but driven by a write
     * command instead of a manual purge: LevelThree::onPut's #[Purge] busts
     * level-three and, via the surrogate-key tags, its parents. This pins the
     * command-driven invalidation flow demonstrated in demo/run-dependency.php.
     */
    public function testWriteToGrandChildCascadesInvalidation(): void
    {
        $this->resource->get('page://self/dep/level-one');
        $one1 = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $one1);
        $etag1 = $one1->headers[Header::ETAG];
        $this->resource->put('page://self/dep/level-three');
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-one')));
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-two')));
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-three')));
        $this->assertFalse($this->storage->hasEtag($etag1));
    }

    /**
     * Test that resources in unrelated dependency chains are independent.
     *
     * Structure:
     * - Chain A: LevelOne -> LevelTwo -> LevelThree
     * - Chain B: ChildC (completely independent)
     *
     * Purging LevelThree should invalidate LevelOne and LevelTwo but NOT ChildC.
     */
    public function testUnrelatedResourcesAreIndependent(): void
    {
        // Access both chains independently
        $this->resource->get('page://self/dep/level-one');
        $this->resource->get('page://self/dep/child-c');

        $levelOne = $this->repository->get(new Uri('page://self/dep/level-one'));
        $childC = $this->repository->get(new Uri('page://self/dep/child-c'));
        $this->assertInstanceOf(ResourceState::class, $levelOne);
        $this->assertInstanceOf(ResourceState::class, $childC);

        // Capture ETags before purge
        $etagLevelOne = $levelOne->headers[Header::ETAG];
        $etagChildC = $childC->headers[Header::ETAG];

        // Purge LevelThree (in Chain A)
        $this->repository->purge(new Uri('page://self/dep/level-three'));

        // LevelOne should be invalidated (it depends on LevelThree via LevelTwo)
        $this->assertNull($this->repository->get(new Uri('page://self/dep/level-one')));
        $this->assertFalse($this->storage->hasEtag($etagLevelOne));

        // ChildC should still be cached (completely unrelated chain)
        $childCAfterPurge = $this->repository->get(new Uri('page://self/dep/child-c'));
        $this->assertInstanceOf(ResourceState::class, $childCAfterPurge);
        $this->assertTrue($this->storage->hasEtag($etagChildC));
    }

    /**
     * Test that purging a child invalidates all parents that depend on it.
     *
     * Structure: ParentA and ParentB both embed ChildC
     * Purging ChildC should invalidate both ParentA and ParentB.
     */
    public function testMultipleParentsDependOnSameChild(): void
    {
        // Access both parents (which both embed ChildC)
        $this->resource->get('page://self/dep/parent-a');
        $this->resource->get('page://self/dep/parent-b');

        $parentA = $this->repository->get(new Uri('page://self/dep/parent-a'));
        $parentB = $this->repository->get(new Uri('page://self/dep/parent-b'));
        $this->assertInstanceOf(ResourceState::class, $parentA);
        $this->assertInstanceOf(ResourceState::class, $parentB);

        // Capture ETags before purge
        $etagA = $parentA->headers[Header::ETAG];
        $etagB = $parentB->headers[Header::ETAG];

        // Purge the shared child
        $this->repository->purge(new Uri('page://self/dep/child-c'));

        // Both parents should be invalidated
        $this->assertNull($this->repository->get(new Uri('page://self/dep/parent-a')));
        $this->assertNull($this->repository->get(new Uri('page://self/dep/parent-b')));

        // ETags should also be invalidated
        $this->assertFalse($this->storage->hasEtag($etagA));
        $this->assertFalse($this->storage->hasEtag($etagB));
    }

    /**
     * Non-Cacheable child has no ETag header, so setCacheDependency must
     * continue past it without registering a (parent, child) dependency. The
     * parent's cached state still gets a Surrogate-Key (its own URI tag),
     * but the child's URI tag must NOT appear in it.
     */
    public function testNonCacheableChildDoesNotContributeSurrogateKey(): void
    {
        $this->resource->get('page://self/dep/parent-of-non-cacheable');

        $parent = $this->repository->get(new Uri('page://self/dep/parent-of-non-cacheable'));
        $this->assertInstanceOf(ResourceState::class, $parent);

        $childTag = (new UriTag())(new Uri('page://self/dep/non-cacheable-child'));
        $surrogateKey = $parent->headers[Header::SURROGATE_KEY] ?? '';
        $this->assertStringNotContainsString($childTag, $surrogateKey);
    }

    public function testHalEmbeddedChildAddsChildSurrogateKeyToParent(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new HalModule());
        $injector = new Injector(new FakeEtagPoolModule($module), __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepositoryInterface::class);

        $resource->get('page://self/hal/parent-resource');

        $parent = $repository->get(new Uri('page://self/hal/parent-resource'));
        $this->assertInstanceOf(ResourceState::class, $parent);
        $childTag = (new UriTag())(new Uri('page://self/hal/child'));

        $this->assertStringContainsString($childTag, $parent->headers[Header::SURROGATE_KEY] ?? '');
    }
}
