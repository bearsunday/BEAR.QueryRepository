<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Dep\CountingChild;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function is_array;
use function json_decode;

class EmbeddedChildMaterializationTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        CountingChild::$count = 0;

        parent::setUp();
    }

    /**
     * One cold parent GET runs the embedded child exactly once
     *
     * The child is materialized by setCacheDependency() and again by the storage,
     * and only AbstractRequest's memoized result makes the second pass free.
     */
    public function testColdGetExecutesEmbeddedChildOnce(): void
    {
        $this->resource->get('page://self/dep/parent-of-counting');

        $this->assertSame(1, CountingChild::$count);
    }

    /**
     * The stored body and the stored view of one entry describe the same child run
     *
     * The view is rendered from the memoized child; if the storage re-invokes the
     * request, a child whose output changes per call leaves the entry holding two
     * different snapshots.
     */
    public function testStoredBodyMatchesStoredView(): void
    {
        $this->resource->get('page://self/dep/parent-of-counting');

        $state = $this->repository->get(new Uri('page://self/dep/parent-of-counting'));
        $this->assertInstanceOf(ResourceState::class, $state);
        assert(is_array($state->body));
        $child = $state->body['child'];
        $this->assertInstanceOf(ResourceObject::class, $child);
        $this->assertIsString($state->view);
        $view = json_decode($state->view, true);
        assert(is_array($view));
        $viewChild = $view['child'];
        assert(is_array($viewChild));
        $childBody = $child->body;
        assert(is_array($childBody));

        $this->assertSame($viewChild['count'], $childBody['count']);
    }

    /**
     * The stored child is a copy: mutating it must not reach the live response graph
     *
     * The live body holds the request, not the child, so the comparison is against the
     * execution the request memoized - the object the renderer and any later reader of
     * the live graph see.
     */
    public function testStoredChildIsNotTheLiveChild(): void
    {
        $ro = $this->resource->get('page://self/dep/parent-of-counting');

        $state = $this->repository->get(new Uri('page://self/dep/parent-of-counting'));
        $this->assertInstanceOf(ResourceState::class, $state);
        assert(is_array($state->body));
        assert(is_array($ro->body));
        $liveChild = $ro->body['child'];
        assert($liveChild instanceof AbstractRequest);

        $this->assertNotSame($liveChild->jsonSerialize(), $state->body['child']);
    }

    /**
     * Storing rewrites a copy: the live graph keeps the requests it was built with
     *
     * The store replaces embedded requests with their results all the way down. Doing that
     * in place would leave the renderer and the response transfer walking a graph whose
     * children were swapped out underneath them, so the rewrite runs on a copy.
     */
    public function testStoringDoesNotMaterializeInsideTheLiveGraph(): void
    {
        $ro = $this->resource->get('page://self/dep/level-one');
        assert(is_array($ro->body));
        $liveChild = $ro->body['level-two'];
        assert($liveChild instanceof AbstractRequest);
        $memo = self::asResourceObject($liveChild->jsonSerialize());
        assert(is_array($memo->body));

        $state = $this->repository->get(new Uri('page://self/dep/level-one'));
        $this->assertInstanceOf(ResourceState::class, $state);
        assert(is_array($state->body));
        $storedChild = $state->body['level-two'];
        $this->assertInstanceOf(ResourceObject::class, $storedChild);
        assert(is_array($storedChild->body));

        $this->assertInstanceOf(RequestInterface::class, $memo->body['level-three'], 'the live child still embeds a request');
        $this->assertInstanceOf(ResourceObject::class, $storedChild->body['level-three'], 'the stored child holds the result');
    }

    /**
     * bear/resource declares the memo accessor as ResourceObject in some releases and
     * mixed in others, so the narrowing lives behind a mixed parameter
     */
    private static function asResourceObject(mixed $value): ResourceObject
    {
        assert($value instanceof ResourceObject);

        return $value;
    }

    /**
     * A request with no memo to read is materialized by running it
     *
     * The memo lives on AbstractRequest, so at the interface level the single
     * execution the evaluator can hand to the store is its own.
     */
    public function testRequestWithoutMemoIsMaterializedByRunningIt(): void
    {
        $request = new FakeEmbeddedRequest();

        $body = (new ResourceBodyEvaluator())(['child' => $request]);

        assert(is_array($body));
        $child = $body['child'];
        $this->assertInstanceOf(ResourceObject::class, $child);
        $this->assertSame(['n' => 1], $child->body);
        $this->assertSame(1, $request->invoked);
    }

    /**
     * A ResourceObject sitting in the body is copied before its own body is rewritten
     *
     * `clone` is shallow, so a materialized parent shares the objects inside its body with
     * the memoized original. Rewriting those in place would mutate the live graph one level
     * below the copy boundary - the failure the boundary exists to prevent.
     */
    public function testResourceObjectInTheBodyIsCopiedBeforeItIsRewritten(): void
    {
        $nested = new FakeEmbeddedRequest();
        $live = new class extends ResourceObject{
        };
        $live->uri = new Uri('page://self/live'); // ResourceObject::__clone() clones the uri
        $live->body = ['nested' => $nested];

        $body = (new ResourceBodyEvaluator())(['child' => $live]);

        assert(is_array($body));
        $stored = $body['child'];
        $this->assertInstanceOf(ResourceObject::class, $stored);
        $this->assertNotSame($live, $stored, 'the store works on its own copy');
        assert(is_array($stored->body));
        $this->assertInstanceOf(ResourceObject::class, $stored->body['nested'], 'the copy holds the materialized result');
        assert(is_array($live->body));
        $this->assertSame($nested, $live->body['nested'], 'the live object still holds its request');
    }
}
