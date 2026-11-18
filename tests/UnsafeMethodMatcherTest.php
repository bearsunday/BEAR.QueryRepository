<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\CaseWriter;
use FakeVendor\HelloWorld\Resource\Page\Mx\CRPurge;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use ReflectionClass;
use ReflectionMethod;

use function property_exists;

class UnsafeMethodMatcherTest extends TestCase
{
    public function testMatchesTheWritesAndNotTheRead(): void
    {
        $matcher = new UnsafeMethodMatcher();
        $matched = [];
        foreach (['onGet', 'onPut', 'onPost', 'onPatch', 'onDelete'] as $method) {
            $matched[$method] = $matcher->matchesMethod(new ReflectionMethod(CRPurge::class, $method), []);
        }

        $this->assertSame(['onGet' => false, 'onPut' => true, 'onPost' => true, 'onPatch' => true, 'onDelete' => true], $matched);
    }

    /** BEAR.Resource dispatches to 'on' . ucfirst($method), so a helper named like a write is never a handler */
    public function testMatchesTheHandlerNameInAnyCaseAndNotAHelperNamedLikeIt(): void
    {
        $matcher = new UnsafeMethodMatcher();

        $this->assertTrue($matcher->matchesMethod(new ReflectionMethod(CaseWriter::class, 'onPUT'), []));
        $this->assertFalse($matcher->matchesMethod(new ReflectionMethod(CaseWriter::class, 'onPostProcess'), []));
    }

    public function testACacheableClassWeavesTheHandlerAndNotTheHelper(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $writer = $injector->getInstance(CaseWriter::class);
        $this->assertTrue(property_exists($writer, 'bindings'));
        /** @var array<string, list<object>> $bindings */
        $bindings = $writer->bindings;
        $woven = [];
        foreach ($bindings as $method => $chain) {
            foreach ($chain as $interceptor) {
                $woven[$method][] = $interceptor::class;
            }
        }

        $this->assertSame(['onGet' => [CacheInterceptor::class], 'onPUT' => [CommandInterceptor::class]], $woven);

        $ro = $injector->getInstance(ResourceInterface::class)->put('app://self/case-writer', ['id' => 1]);

        $this->assertSame(204, $ro->code);
    }

    public function testMatchesNoClass(): void
    {
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass(CRPurge::class);

        $this->assertFalse((new UnsafeMethodMatcher())->matchesClass($class, []));
    }
}
