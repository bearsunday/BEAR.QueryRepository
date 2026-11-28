<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function iterator_to_array;

class CacheDependencyCollectorTest extends TestCase
{
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $this->injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
    }

    public function testCollectRendersOnceAndReusesTheRequestResult(): void
    {
        $child = new class extends ResourceObject {
        };
        $child->uri = new Uri('app://self/child');
        $child->view = 'child';
        $invoker = new class implements InvokerInterface {
            public int $calls = 0;

            #[Override]
            public function invoke(AbstractRequest $request): ResourceObject
            {
                $this->calls++;
                $request->resourceObject->body = [];
                $request->resourceObject->headers[Header::SURROGATE_KEY] = 'data';

                return $request->resourceObject;
            }
        };
        $request = new Request($invoker, $child);
        $parent = new class extends ResourceObject {
        };
        $parent->uri = new Uri('app://self/parent');
        $parent->body = ['scalar', $child, [$request], $request];
        $evaluator = $this->injector->getInstance(ResourceBodyEvaluator::class);

        $this->assertSame([$request], iterator_to_array($evaluator->requests($parent)));
        $this->assertSame(0, $invoker->calls);
        $this->injector->getInstance(CacheDependencyCollector::class)->collect($parent);
        $this->assertSame(1, $invoker->calls);
        $this->assertSame('_child_ grp-_child_ data', $parent->headers[Header::SURROGATE_KEY]);
        $this->assertSame('child', (string) $request);
        $this->assertSame(1, $invoker->calls);
    }

    public function testMaterialCollectionDoesNotRunAHoleAndLeavesItsTagsOut(): void
    {
        $hole = new class extends ResourceObject {
        };
        $hole->uri = new Uri('app://self/hole');
        $material = new class extends ResourceObject {
        };
        $material->uri = new Uri('app://self/material');
        $material->body = [];
        $material->headers[Header::SURROGATE_KEY] = 'material-data';
        $invoker = new class implements InvokerInterface {
            public int $calls = 0;

            #[Override]
            public function invoke(AbstractRequest $request): ResourceObject
            {
                $this->calls++;

                return $request->resourceObject;
            }
        };
        $parent = new class extends ResourceObject {
        };
        $parent->uri = new Uri('app://self/parent');
        $parent->body = [new Request($invoker, $hole), new Request($invoker, $material)];
        $keys = $this->dependencies($parent->uri);

        $collector = $this->injector->getInstance(CacheDependencyCollector::class);
        $collector->materials($parent, $keys);

        $this->assertSame(0, $invoker->calls);
        $this->assertSame('_material_ grp-_material_ material-data', $parent->headers[Header::SURROGATE_KEY]);
    }

    private function dependencies(AbstractUri $uri): Dependencies
    {
        return $this->injector->getInstance(CacheDependencyCollector::class)->begin(new TagSet([(new UriTag(new CacheIdentityFactory(new GlobalServerContext())))($uri)]));
    }
}
