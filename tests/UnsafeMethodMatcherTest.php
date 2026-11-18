<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\Mx\CRPurge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

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

    public function testMatchesNoClass(): void
    {
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass(CRPurge::class);

        $this->assertFalse((new UnsafeMethodMatcher())->matchesClass($class, []));
    }
}
