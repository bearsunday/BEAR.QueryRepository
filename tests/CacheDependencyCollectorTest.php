<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\NullSemanticLogger;
use Override;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class CacheDependencyCollectorTest extends TestCase
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
        $child->body = [$second];
        $parent->body = (object) ['second' => $second, 'nested' => [$first], 'child' => $child, 'first' => $first, 'again' => $second];

        // INV-08: enumeration preserves order and multiplicity without running a child.
        $this->assertSame([$second, $first, $second], iterator_to_array(CacheDependencyCollector::requests($parent)));
        $this->assertSame(0, $invoker->calls);

        $parent->body = (static function () use ($first, $invoker) {
            $invoker->calls++;
            yield $first;
        })();

        $this->assertSame([], iterator_to_array(CacheDependencyCollector::requests($parent)));
        $this->assertSame(0, $invoker->calls);
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
        $tags = new DependsOnTags(new UriTag());
        $dependency = new CacheDependency(new UriTag());
        $logger = new NullSemanticLogger();

        $this->assertSame([$request], iterator_to_array(CacheDependencyCollector::requests($parent)));
        $this->assertSame(0, $invoker->calls);
        CacheDependencyCollector::collect($parent, $tags, $dependency, $logger);
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
        $keys = new SurrogateKeys($parent->uri);

        CacheDependencyCollector::collect($parent, new DependsOnTags(new UriTag()), new CacheDependency(new UriTag()), new NullSemanticLogger(), $keys);

        $this->assertSame(0, $invoker->calls);
        $this->assertSame('_material_ grp-_material_ material-data', $parent->headers[Header::SURROGATE_KEY]);
    }

    public function testRawTagsPreserveHeaderEmptyElementsAndExplicitOverride(): void
    {
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('app://self/child');
        $ro->headers[Header::SURROGATE_KEY] = ' data  data ';
        $uriTag = new UriTag();
        $baseUriTag = new BaseUriTag($uriTag);

        $this->assertSame(['_child_', 'grp-_child_', '', 'data', '', 'data', ''], CacheDependencyCollector::tags($ro, $uriTag, $baseUriTag));
        $this->assertSame(['_child_', 'grp-_child_'], CacheDependencyCollector::tags($ro, $uriTag, $baseUriTag, []));
    }
}
