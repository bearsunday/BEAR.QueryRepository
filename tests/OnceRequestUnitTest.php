<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Method;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Counter;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;
use function is_array;

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
        if (! method_exists(ResourceInterface::class, 'newRequest')) {
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
}
