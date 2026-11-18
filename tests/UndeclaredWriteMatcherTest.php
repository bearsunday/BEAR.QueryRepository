<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FakeVendor\HelloWorld\Resource\App\CacheablePagedCategoryWriter;
use FakeVendor\HelloWorld\Resource\Page\Mx\CANone;
use FakeVendor\HelloWorld\Resource\Page\Mx\CARefresh;
use FakeVendor\HelloWorld\Resource\Page\Mx\CRPurge;
use FakeVendor\HelloWorld\Resource\Page\Mx\MRWrite;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class UndeclaredWriteMatcherTest extends TestCase
{
    public function testMatchesAWriteThatDeclaresNoneOfTheAttributes(): void
    {
        $matcher = new UndeclaredWriteMatcher();
        $matched = [];
        foreach (
            [
                'no attribute' => [CANone::class, 'onPut'],
                'onGet' => [CANone::class, 'onGet'],
                '#[Purge]' => [CRPurge::class, 'onPut'],
                '#[Refresh]' => [CacheablePagedCategoryWriter::class, 'onPut'],
                '#[RefreshCache]' => [CARefresh::class, 'onPut'],
                '#[CacheableResponse]' => [MRWrite::class, 'onPut'],
            ] as $case => [$class, $method]
        ) {
            $matched[$case] = $matcher->matchesMethod(new ReflectionMethod($class, $method), []);
        }

        $this->assertSame(
            ['no attribute' => true, 'onGet' => false, '#[Purge]' => false, '#[Refresh]' => false, '#[RefreshCache]' => false, '#[CacheableResponse]' => false],
            $matched,
        );
    }

    public function testMatchesNoClass(): void
    {
        /** @var ReflectionClass<object> $class */
        $class = new ReflectionClass(CANone::class);

        $this->assertFalse((new UndeclaredWriteMatcher())->matchesClass($class, []));
    }
}
