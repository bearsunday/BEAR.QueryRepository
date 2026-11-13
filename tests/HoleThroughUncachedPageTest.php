<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\HalModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\VersionedLeaf;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function dirname;

/**
 * A #[CacheableResponse] page whose hole is an uncached page over a cached resource is invalidated with that resource.
 * The hole's class gives it a body before it runs, so it cannot be told from a resource that ran by its body alone.
 */
class HoleThroughUncachedPageTest extends TestCase
{
    /** @return iterable<string, array{string, AbstractModule|null}> */
    public static function pages(): iterable
    {
        $renderers = [
            'JSON' => null,
            'HAL' => new HalModule(),
            'Twig' => new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']),
        ];
        $pages = [
            'the hole' => 'page://self/html/response-over-uncached-middle',
            'the hole declaring a dependency of its own' => 'page://self/html/response-over-declaring-middle',
        ];
        foreach ($pages as $page => $uri) {
            foreach ($renderers as $name => $renderer) {
                yield "{$page}, {$name}" => [$uri, $renderer];
            }
        }
    }

    #[DataProvider('pages')]
    public function testAPurgeOfTheResourceBelowTheHoleRebuildsThePage(string $uri, AbstractModule|null $renderer): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        if ($renderer !== null) {
            $module->override($renderer);
        }

        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $repository = $injector->getInstance(QueryRepositoryInterface::class);
        // The pools outlive one data set; start with neither the page nor the resource stored
        $repository->purge(new Uri($uri));
        $repository->purge(new Uri('app://self/versioned-leaf'));
        VersionedLeaf::$version = 'v1';
        $this->assertStringContainsString('v1', $resource->get($uri)->toString());

        VersionedLeaf::$version = 'v2';
        $repository->purge(new Uri('app://self/versioned-leaf'));
        $this->assertStringContainsString('v2', $resource->get($uri)->toString());
    }
}
