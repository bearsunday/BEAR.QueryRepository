<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
     */
    public function testStoredChildIsNotTheLiveChild(): void
    {
        $ro = $this->resource->get('page://self/dep/parent-of-counting');

        $state = $this->repository->get(new Uri('page://self/dep/parent-of-counting'));
        $this->assertInstanceOf(ResourceState::class, $state);
        assert(is_array($state->body));
        assert(is_array($ro->body));

        $this->assertNotSame($ro->body['child'], $state->body['child']);
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
}
