<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function explode;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/** A #[Cacheable] child that has no template cannot be rendered under an HTML renderer: its parent still depends on it */
class TemplatelessChildDependencyTest extends TestCase
{
    private const CHILD = 'page://self/dep/child-c';
    private const CHILD_TAG = '_dep_child-c_';

    public function testTheChildIsStoredWithNoView(): void
    {
        $app = new FakeObservedApp();

        $this->assertNull($app->resource->get(self::CHILD)->view);
        $this->assertSame(['ro-_dep_child-c_'], array_keys($app->roEntries()));
    }

    public function testACacheableParentDependsOnTheChild(): void
    {
        $app = new FakeObservedApp();
        // Collecting the dependencies of a value casts each child to a string: a rendering that fails there is a warning
        set_error_handler(static fn (): bool => true, E_USER_WARNING);
        try {
            $parent = $app->resource->get('page://self/dep/parent-a');
        } finally {
            restore_error_handler();
        }

        $this->assertPurgingTheChildDropsTheParent($app, $parent, ['ro-_dep_child-c_', 'ro-_dep_parent-a_']);
    }

    public function testACacheableResponseParentDependsOnTheChild(): void
    {
        $app = new FakeObservedApp();
        $parent = $app->resource->get('page://self/html/cacheable-response-with-templateless-material');

        $this->assertSame('cacheable-response-with-templateless-material:1', $parent->view);
        $this->assertPurgingTheChildDropsTheParent($app, $parent, [
            'donut-_html_cacheable-response-with-templateless-material_',
            'ro-_dep_child-c_',
            'ro-_html_cacheable-response-with-templateless-material_',
        ]);
    }

    public function testADonutCacheParentDependsOnTheChild(): void
    {
        $app = new FakeObservedApp();
        $parent = $app->resource->get('page://self/html/donut-cache-with-templateless-material');

        $this->assertSame('donut-cache-with-templateless-material:1', $parent->view);
        $this->assertPurgingTheChildDropsTheParent($app, $parent, [
            'donut-_html_donut-cache-with-templateless-material_',
            'ro-_dep_child-c_',
        ]);
    }

    /** @param list<string> $entries the keys the miss of the parent stored */
    private function assertPurgingTheChildDropsTheParent(FakeObservedApp $app, ResourceObject $parent, array $entries): void
    {
        $this->assertContains(self::CHILD_TAG, explode(' ', $parent->headers[Header::SURROGATE_KEY]));
        $this->assertSame($entries, array_keys($app->roEntries()));

        $app->injector->getInstance(QueryRepositoryInterface::class)->purge(new Uri(self::CHILD));

        $this->assertSame([], $app->roEntries());
    }
}
