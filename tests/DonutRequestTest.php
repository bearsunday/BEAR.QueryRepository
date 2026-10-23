<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Resource;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use RuntimeException;

use function assert;
use function dirname;
use function http_build_query;

class DonutRequestTest extends TestCase
{
    use SemanticLogTreeTrait;

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
        $request = new DonutRequest($this->request, $storage, new SurrogateKeys(new Uri('app://self/foo')), new UncacheableMark());
        $view = (string) $request;
        $this->assertSame('[le:page://self/html/comment]', $view);

        return $storage;
    }

    /** @depends testToString */
    public function testRender(DonutRenderer $storage): void
    {
        $view = $storage->render('cmt=[le:page://self/html/comment]');
        $this->assertSame('cmt=comment01', $view);
    }

    public function testHoleScopeIsLeftWhenTheHoleThrows(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $injector = new Injector($module, __DIR__ . '/tmp');
        /** @var Resource $resource */
        $resource = $injector->getInstance(ResourceInterface::class);
        $throwingRequest = $resource->get->uri('app://self/throwing-hole');
        assert($throwingRequest instanceof AbstractRequest);

        // The same instance ThrowingHole itself resolves via DI (UncacheableMarkInterface
        // is Scope::SINGLETON): its own mark() call and this test's check share one state.
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $donutRequest = new DonutRequest($throwingRequest, new DonutRenderer(), new SurrogateKeys(new Uri('app://self/foo')), $mark);

        $this->expectException(RuntimeException::class);
        try {
            (string) $donutRequest;
        } finally {
            // ThrowingHole marks itself - attributed to its own scope - before throwing. If
            // leaveHole() ran despite the exception, that scope is popped and the current
            // scope is top-level, which was never marked; if leaveHole() did not run, the
            // hole's scope is still current and this reads as marked, wrongly, from here.
            $this->assertFalse($mark->isMarkedForCurrentScope(), 'the hole\'s own mark must not leak into the current (top-level) scope once its scope is left');
        }
    }

    public function testToStringCarriesAQueryStringSetViaTheRequestsOwnQueryArray(): void
    {
        // A hole's query can arrive via the request's own $query array - e.g. a composing
        // page's named-parameter binding - not baked into the URI string used to build the
        // lazy request, so resourceObject->uri->query is still empty here, before
        // invocation. AbstractRequest::__invoke() is what syncs it (its first statement),
        // so a placeholder/view-map URI read before invocation can be missing it (issue
        // #222 regression).
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, __DIR__ . '/tmp');
        /** @var Resource $resource */
        $resource = $injector->getInstance(ResourceInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);

        $request = $resource->get->uri('app://self/query-hole')->withQuery(['token' => 'public-9', 'visibility' => 'public']);
        assert($request instanceof AbstractRequest);

        $storage = new DonutRenderer();
        $donutRequest = new DonutRequest($request, $storage, new SurrogateKeys(new Uri('app://self/foo')), $mark);
        $placeholder = (string) $donutRequest;

        $expectedUri = 'app://self/query-hole?' . http_build_query(['token' => 'public-9', 'visibility' => 'public']);
        $this->assertSame('[le:' . $expectedUri . ']', $placeholder, 'the placeholder must carry the query string set via the request\'s own query array');
        $this->assertSame('public-9', $storage->render('[le:' . $expectedUri . ']'), 'the stored view must be keyed by the exact same full URI the placeholder carries');
        $this->assertFalse($mark->isMarked(), 'visibility=public must not mark');
    }

    public function testToStringCarriesAQueryStringEvenWhenTheHoleMarksItself(): void
    {
        // Same as above, with a query that puts the hole in its marking state - the
        // query-string fix and the mark/no-mark decision are independent of each other.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, __DIR__ . '/tmp');
        /** @var Resource $resource */
        $resource = $injector->getInstance(ResourceInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);

        $request = $resource->get->uri('app://self/query-hole')->withQuery(['token' => 'secret-5', 'visibility' => 'private']);
        assert($request instanceof AbstractRequest);

        $storage = new DonutRenderer();
        $donutRequest = new DonutRequest($request, $storage, new SurrogateKeys(new Uri('app://self/foo')), $mark);
        $placeholder = (string) $donutRequest;

        $expectedUri = 'app://self/query-hole?' . http_build_query(['token' => 'secret-5', 'visibility' => 'private']);
        $this->assertSame('[le:' . $expectedUri . ']', $placeholder, 'the placeholder must carry the query string even though the hole marked itself');
        $this->assertTrue($mark->isMarked(), 'visibility=private must mark');
    }

    public function testSiblingHolesOnTheSamePathWithDifferentQueriesDoNotShareAnOwnerSlot(): void
    {
        // A query-less enterHole() key would let two embeds of the same path but
        // different query args share one owner slot: a #[DonutCache] hole whose own
        // template write checks reasonExcludingHoleMarks() (isMarkedForCurrentScope(),
        // narrow/scoped) would then read as marked anyway, and refuse ITS OWN template
        // write, because a sibling with a different query marked itself (over-refusal).
        // Unreachable through #[Embed] itself - EmbedInterceptor expands the URI template
        // before building the request, so an #[Embed] request always already carries its
        // query - but reachable through two manually built requests with different
        // queries on the same path, exactly as issue #222 follow-up code (a worker
        // rebinding the mark per request, say) might construct one.
        //
        // Checked via the semantic log right after the second write, not a later live GET:
        // a live response's headers reflect isMarked() (broad, "any mark anywhere in this
        // request"), which stays true for the rest of this shared mark instance regardless
        // of this fix - only the per-write put_skipped/save_donut event, read at the point
        // of the second write itself, observes the scoped template-storage decision.
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, __DIR__ . '/tmp');
        /** @var Resource $resource */
        $resource = $injector->getInstance(ResourceInterface::class);
        $mark = $injector->getInstance(UncacheableMarkInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

        // The first hole marks itself - attributed to its own (query-bearing) scope only.
        $markedRequest = $resource->get->uri('app://self/query-donut-hole')->withQuery(['token' => 'secret-5', 'visibility' => 'private']);
        assert($markedRequest instanceof AbstractRequest);
        $storage = new DonutRenderer();
        $etags = new SurrogateKeys(new Uri('app://self/foo'));
        (string) new DonutRequest($markedRequest, $storage, $etags, $mark);
        $logger->flush();

        // A sibling on the same path, a different query, never marks itself - wrapped the
        // same way #[Embed] would (DonutRequest's own enterHole()/leaveHole()); its own
        // #[DonutCache] template write runs here, inside its own scope.
        $unmarkedRequest = $resource->get->uri('app://self/query-donut-hole')->withQuery(['token' => 'public-9', 'visibility' => 'public']);
        assert($unmarkedRequest instanceof AbstractRequest);
        (string) new DonutRequest($unmarkedRequest, $storage, $etags, $mark);
        $tree = $this->flushAndValidate($logger);

        $this->assertNull(self::eventContextJsonOf($tree, 'put_skipped'), 'a sibling hole on the same path but a different query must not inherit a mark attributed to another query\'s owner slot');
        $saveDonut = self::eventContextJsonOf($tree, 'save_donut');
        $this->assertNotNull($saveDonut);
        $this->assertStringContainsString('"saved":true', $saveDonut, 'the second hole\'s own template write must still go through');
    }
}
