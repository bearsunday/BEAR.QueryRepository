<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function iterator_to_array;

class DependsOnTagsTest extends TestCase
{
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $this->injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
    }

    public function testRequestsPreserveOrderAndDuplicatesWithoutConsumingTraversableBodies(): void
    {
        $child = new class extends ResourceObject {
        };
        $child->uri = new Uri('app://self/child');
        $invoker = new class implements InvokerInterface {
            public int $calls = 0;

            #[Override]
            public function invoke(AbstractRequest $request): ResourceObject
            {
                $this->calls++;

                return $request->resourceObject;
            }
        };
        $first = new Request($invoker, $child);
        $second = new Request($invoker, clone $child);
        $parent = clone $child;
        $tags = $this->injector->getInstance(DependsOnTags::class);
        $child->body = [$second];
        $parent->body = (object) ['second' => $second, 'nested' => [$first], 'child' => $child, 'first' => $first, 'again' => $second];

        // INV-08: enumeration preserves order and multiplicity without running a child.
        $this->assertSame([$second, $first, $second], iterator_to_array($tags->requests($parent)));
        $this->assertSame(0, $invoker->calls);

        $parent->body = (static function () use ($first, $invoker) {
            $invoker->calls++;
            yield $first;
        })();

        $this->assertSame([], iterator_to_array($tags->requests($parent)));
        $this->assertSame(0, $invoker->calls);
    }
}
