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

use function iterator_to_array;

class DependsOnTagsTest extends TestCase
{
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
        $tags = new DependsOnTags(new UriTag());
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
