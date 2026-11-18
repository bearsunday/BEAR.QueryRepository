<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\Page\Mx\CANone;
use FakeVendor\HelloWorld\Resource\Page\Mx\CRNone;
use FakeVendor\HelloWorld\Resource\Page\Mx\DCNone;
use FakeVendor\HelloWorld\Resource\Page\Mx\MRNone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CacheableClassMatcherTest extends TestCase
{
    public function testMatchesAClassThatStoresItsWholeResponse(): void
    {
        $matcher = new CacheableClassMatcher();
        $matched = [];
        foreach ([CANone::class, CRNone::class, DCNone::class, MRNone::class] as $class) {
            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($class);
            $matched[$class] = $matcher->matchesClass($reflection, []);
        }

        $this->assertSame([CANone::class => true, CRNone::class => true, DCNone::class => false, MRNone::class => false], $matched);
    }

    public function testMatchesNoMethod(): void
    {
        $this->assertFalse((new CacheableClassMatcher())->matchesMethod(new ReflectionMethod(MRNone::class, 'onGet'), []));
    }
}
