<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/**
 * Stress test for the cache dependency resolution with a DIAMOND graph.
 *
 *            diamond-top
 *            /         \
 *     diamond-left   diamond-right
 *            \         /
 *           diamond-bottom
 *
 * `diamond-top` embeds BOTH `diamond-left` and `diamond-right` (multi-embed);
 * each arm embeds the shared leaf `diamond-bottom`. This exercises cases the
 * existing chain / single-embed fixtures never reach:
 *  - a parent that embeds more than one child (multi-embed accumulation),
 *  - a leaf reachable through two independent paths,
 *  - precise (non-over-reaching) invalidation of a single arm.
 */
class ComplexCacheDependencyTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);

        parent::setUp();
    }

    private function isCached(string $name): bool
    {
        return $this->repository->get(new Uri('page://self/dep/' . $name)) instanceof ResourceState;
    }

    public function testDiamondBuildsEveryNode(): void
    {
        $this->resource->get('page://self/dep/diamond-top');

        foreach (['diamond-top', 'diamond-left', 'diamond-right', 'diamond-bottom'] as $name) {
            $this->assertTrue($this->isCached($name), $name . ' should be cached after the build');
        }
    }

    public function testMultiEmbedParentDependsOnEveryChild(): void
    {
        // Regression: diamond-top embeds BOTH arms. Purging EITHER arm must invalidate top.
        // Before the fix, the second embed overwrote the first, so one arm's dependency was lost.
        $this->resource->get('page://self/dep/diamond-top');
        $this->repository->purge(new Uri('page://self/dep/diamond-left'));
        $this->assertFalse($this->isCached('diamond-top'), 'top must depend on its LEFT child');

        $this->resource->get('page://self/dep/diamond-top'); // rebuild
        $this->repository->purge(new Uri('page://self/dep/diamond-right'));
        $this->assertFalse($this->isCached('diamond-top'), 'top must also depend on its RIGHT child');
    }

    public function testPurgingSharedLeafCascadesThroughBothPaths(): void
    {
        $this->resource->get('page://self/dep/diamond-top');

        // diamond-bottom is embedded via both arms; purging it invalidates every ancestor.
        $this->repository->purge(new Uri('page://self/dep/diamond-bottom'));

        $this->assertFalse($this->isCached('diamond-top'), 'top reaches bottom via both arms');
        $this->assertFalse($this->isCached('diamond-left'), 'left embeds bottom');
        $this->assertFalse($this->isCached('diamond-right'), 'right embeds bottom');
    }

    public function testPurgingOneArmIsPreciseAndDoesNotOverReach(): void
    {
        $this->resource->get('page://self/dep/diamond-top');

        // Purge only the left arm.
        $this->repository->purge(new Uri('page://self/dep/diamond-left'));

        $this->assertFalse($this->isCached('diamond-top'), 'top embeds left -> invalidated');
        $this->assertTrue($this->isCached('diamond-right'), 'the right arm is independent of left');
        $this->assertTrue($this->isCached('diamond-bottom'), 'the shared leaf survives an arm purge');
    }

    public function testRebuildAfterPurgeRePropagatesDependencies(): void
    {
        $this->resource->get('page://self/dep/diamond-top');
        $this->repository->purge(new Uri('page://self/dep/diamond-bottom'));
        $this->assertFalse($this->isCached('diamond-top'), 'cascade invalidated top');

        // Rebuild and confirm the dependency graph is restored (not a one-shot).
        $this->resource->get('page://self/dep/diamond-top');
        $this->assertTrue($this->isCached('diamond-top'));
        $this->repository->purge(new Uri('page://self/dep/diamond-bottom'));
        $this->assertFalse($this->isCached('diamond-top'), 'cascade still works after a rebuild');
    }

    public function testUnrelatedResourceIsUnaffectedByDiamondPurges(): void
    {
        $this->resource->get('page://self/dep/diamond-top');
        $this->resource->get('page://self/dep/child-c'); // unrelated chain

        $this->repository->purge(new Uri('page://self/dep/diamond-bottom'));

        $this->assertTrue($this->isCached('child-c'), 'an unrelated resource must not be touched');
    }
}
