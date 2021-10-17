<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;

class UtiTagTest extends TestCase
{
    public function testInvoke(): void
    {
        $key = (new UriTag())(new Uri('app://self/foo?a=1&b=2'));
        $this->assertSame('_foo_a=1&b=2', (string) $key);
    }
}
