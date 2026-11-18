<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\App\CacheablePagedCategoryWriter;
use FakeVendor\HelloWorld\Resource\Page\Mx\CANone;
use FakeVendor\HelloWorld\Resource\Page\Mx\CARefresh;
use FakeVendor\HelloWorld\Resource\Page\Mx\CRPurge;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class CommandMethodMatcherTest extends TestCase
{
    public function testMatchesAMethodWithPurgeOrRefresh(): void
    {
        $matcher = new CommandMethodMatcher();

        $this->assertTrue($matcher->matchesMethod(new ReflectionMethod(CRPurge::class, 'onPut'), []), '#[Purge]');
        $this->assertTrue($matcher->matchesMethod(new ReflectionMethod(CacheablePagedCategoryWriter::class, 'onPut'), []), '#[Refresh]');
        $this->assertFalse($matcher->matchesMethod(new ReflectionMethod(CARefresh::class, 'onPut'), []), '#[RefreshCache]');
        $this->assertFalse($matcher->matchesMethod(new ReflectionMethod(CANone::class, 'onPut'), []), 'none');
    }

    public function testMatchesNoClass(): void
    {
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass(CRPurge::class);

        $this->assertFalse((new CommandMethodMatcher())->matchesClass($class, []));
    }
}
