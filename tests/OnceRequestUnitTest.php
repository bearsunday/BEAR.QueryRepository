<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Method;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Counter;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function is_array;
use function method_exists;

/**
 * Unit-level coverage for the OnceRequest delegate/discard methods that the end-to-end
 * donut scenarios in OnceRequestBehaviorTest never happen to exercise directly
 * (addQuery(), toUriWithMethod(), link*(), and __invoke()'s own $query argument).
 */
class OnceRequestUnitTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        if (! method_exists(ResourceInterface::class, 'newRequest')) { // @phpstan-ignore-line
            self::markTestSkipped('this bear/resource release has no ResourceInterface::newRequest() - the delegate/discard methods this suite covers are exercised end to end instead, in OnceRequestBehaviorTest');
        }

        Counter::$calls = 0;
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
    }

    private function newCounterRequest(): Request
    {
        $request = $this->resource->newRequest(Method::GET, 'app://self/counter');
        self::assertInstanceOf(Request::class, $request);

        return $request;
    }

    public function testAddQueryDiscardsTheMemoAndReExecutes(): void
    {
        $once = new OnceRequest($this->newCounterRequest());
        $first = $once();
        $this->assertTrue($once->hasExecuted());

        $once->addQuery(['tag' => 'added']);
        $this->assertFalse($once->hasExecuted(), 'addQuery() must discard the memo, same as withQuery()');

        $second = $once();
        $this->assertSame(2, Counter::$calls);
        $this->assertNotSame($first, $second);
        assert(is_array($second->body));
        $this->assertSame('added', $second->body['tag']);
    }

    public function testInvokeWithAQueryArgumentMergesAndReExecutes(): void
    {
        $once = new OnceRequest($this->newCounterRequest());
        $once();
        $this->assertSame(1, Counter::$calls);

        $second = $once(['tag' => 'merged']);
        $this->assertSame(2, Counter::$calls, '__invoke($query) must force a second execution under the merged query');
        assert(is_array($second->body));
        $this->assertSame('merged', $second->body['tag']);
    }

    public function testToUriWithMethodDelegatesToTheInnerRequest(): void
    {
        $inner = $this->newCounterRequest();
        $once = new OnceRequest($inner);

        $this->assertSame($inner->toUriWithMethod(), $once->toUriWithMethod());
    }

    public function testLinkSelfDelegatesAndDiscardsTheMemo(): void
    {
        $once = new OnceRequest($this->newCounterRequest());
        $once();
        $this->assertTrue($once->hasExecuted());

        $once->linkSelf('someLink');
        $this->assertFalse($once->hasExecuted(), 'a link change must discard the memo too - the next invocation is a linked one');
        $this->assertNotSame([], $once->links);
    }

    public function testLinkNewDelegatesAndDiscardsTheMemo(): void
    {
        $once = new OnceRequest($this->newCounterRequest());
        $once();

        $once->linkNew('someLink');
        $this->assertFalse($once->hasExecuted());
        $this->assertNotSame([], $once->links);
    }

    public function testLinkCrawlDelegatesAndDiscardsTheMemo(): void
    {
        $once = new OnceRequest($this->newCounterRequest());
        $once();

        $once->linkCrawl('someLink');
        $this->assertFalse($once->hasExecuted());
        $this->assertNotSame([], $once->links);
    }

    public function testGetCodeReadsFromTheMemoizedExecutionWithoutReExecuting(): void
    {
        $once = new OnceRequest($this->newCounterRequest());

        $code = $once->code;
        $this->assertSame(1, Counter::$calls, '__get() must run through this class\'s own memo, not AbstractRequest\'s separate one');

        $this->assertSame($code, $once->code);
        $this->assertSame(1, Counter::$calls, 'a second __get() for the same generation must not execute again');
    }

    public function testGetBodyMatchesTheSameExecutionCodeCameFrom(): void
    {
        $once = new OnceRequest($this->newCounterRequest());

        $body = $once->body;
        assert(is_array($body));
        $this->assertSame(1, $body['call'], 'the body must come from the one execution __get(\'code\') above would also have read');
    }

    public function testGetHeadersAlsoReadsFromTheSameMemoizedExecution(): void
    {
        $once = new OnceRequest($this->newCounterRequest());

        $executed = $once();
        $this->assertSame(1, Counter::$calls);

        $this->assertSame($executed->headers, $once->headers, '__get(\'headers\') must answer the same execution __invoke() already memoized, not a fresh one');
        $this->assertSame(1, Counter::$calls, 'reading headers after an already-memoized execution must not run a second one');
    }

    public function testGetEagerSetsInOnBothWrapperAndInnerRequestAndReturnsItself(): void
    {
        $inner = $this->newCounterRequest();
        $once = new OnceRequest($inner);

        $result = $once->eager;

        $this->assertSame($once, $result, 'the eager/lazy DSL returns $this for chaining, same as Request::__get()');
        $this->assertSame('eager', $once->in);
        $this->assertSame('eager', $inner->in, 'must forward to the inner request too, not only set the wrapper\'s own $in');
    }

    public function testGetLazySetsInOnBothWrapperAndInnerRequest(): void
    {
        $inner = $this->newCounterRequest();
        $once = new OnceRequest($inner);

        $flippedToEager = $once->eager; // flip away from the default 'lazy' first, so this assertion is not a no-op
        $flippedToLazy = $flippedToEager->lazy;

        $this->assertSame($once, $flippedToLazy, 'the DSL keeps returning $this across the chain');
        $this->assertSame('lazy', $once->in);
        $this->assertSame('lazy', $inner->in);
    }

    public function testGetUnknownPropertyThrowsOutOfRangeException(): void
    {
        $once = new OnceRequest($this->newCounterRequest());

        $this->expectException(OutOfRangeException::class);
        $once->notAKnownProperty; // @phpstan-ignore-line
    }
}
