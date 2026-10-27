<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\Resource;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use RuntimeException;

use function assert;
use function dirname;
use function http_build_query;

class DonutRequestTest extends TestCase
{
    private AbstractRequest $request;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, __DIR__ . '/tmp');
        /** @var Resource $resource */
        $resource = $injector->getInstance(ResourceInterface::class);
        $request = $resource->get->uri('page://self/html/comment');
        assert($request instanceof AbstractRequest);
        $this->request = $request;

        parent::setUp();
    }

    public function testToString(): DonutRenderer
    {
        $storage = new DonutRenderer();
        $request = new DonutRequest($this->request, $storage, new SurrogateKeys(new Uri('app://self/foo')));
        $view = (string) $request;
        $this->assertSame(ResourceDonut::placeholder('page://self/html/comment'), $view);

        return $storage;
    }

    /** @depends testToString */
    public function testRender(DonutRenderer $storage): void
    {
        $view = $storage->render('cmt=' . ResourceDonut::placeholder('page://self/html/comment'));
        $this->assertSame('cmt=comment01', $view);
    }

    public function testANonRequestHoleIsInvokedDirectlyOnceWithoutTouchingJsonSerialize(): void
    {
        $child = new class extends ResourceObject {
        };
        $child->uri = new Uri('app://self/non-request');
        $child->view = 'non-request-view';
        $invoker = new class ($child) implements InvokerInterface {
            public int $calls = 0;

            public function __construct(private readonly ResourceObject $ro)
            {
            }

            public function invoke(AbstractRequest $request): ResourceObject
            {
                $this->calls++;

                return $this->ro;
            }
        };
        $request = new FakeNonRequestHole($invoker, $child);
        $storage = new DonutRenderer();
        $donutRequest = new DonutRequest($request, $storage, new SurrogateKeys(new Uri('app://self/foo')));

        $placeholder = (string) $donutRequest;

        $this->assertSame(1, $invoker->calls);
        $this->assertSame(0, $request->jsonSerializeCalls, 'jsonSerialize() of a non-Request (e.g. AsyncRequest) has side effects');
        $this->assertSame('hole=non-request-view', $storage->render('hole=' . $placeholder));
    }

    public function testHoleFailureTravelsToTheCaller(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $injector = new Injector($module, __DIR__ . '/tmp');
        /** @var Resource $resource */
        $resource = $injector->getInstance(ResourceInterface::class);
        $throwingRequest = $resource->get->uri('app://self/throwing-hole');
        assert($throwingRequest instanceof AbstractRequest);

        $donutRequest = new DonutRequest($throwingRequest, new DonutRenderer(), new SurrogateKeys(new Uri('app://self/foo')));

        $this->expectException(RuntimeException::class);
        (string) $donutRequest;
    }

    public function testToStringCarriesAQueryStringSetViaTheRequestsOwnQueryArray(): void
    {
        // A hole's query can arrive via the request's own $query array - e.g. a composing
        // page's named-parameter binding - not baked into the URI string used to build the
        // lazy request, so resourceObject->uri->query is still empty here, before
        // invocation. AbstractRequest::__invoke() is what syncs it (its first statement),
        // so a placeholder/view-map URI read before invocation can be missing it.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, __DIR__ . '/tmp');
        /** @var Resource $resource */
        $resource = $injector->getInstance(ResourceInterface::class);

        $request = $resource->get->uri('app://self/query-hole')->withQuery(['token' => 'public-9']);
        assert($request instanceof AbstractRequest);

        $storage = new DonutRenderer();
        $donutRequest = new DonutRequest($request, $storage, new SurrogateKeys(new Uri('app://self/foo')));
        $placeholder = (string) $donutRequest;

        $expectedUri = 'app://self/query-hole?' . http_build_query(['token' => 'public-9']);
        $this->assertSame(ResourceDonut::placeholder($expectedUri), $placeholder, 'the placeholder must carry the query string set via the request\'s own query array');
        $this->assertSame('public-9', $storage->render(ResourceDonut::placeholder($expectedUri)), 'the stored view must be keyed by the exact same full URI the placeholder carries');
    }
}
