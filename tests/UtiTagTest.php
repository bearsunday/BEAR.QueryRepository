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

    public function testFromAssoc(): void
    {
        $list = (new UriTag())->fromAssoc(
            'app://self/item{?id}',
            [
                ['id' => '1', 'name' => 'a'],
                ['id' => '2', 'name' => 'b'],
            ]
        );
        $this->assertSame('_item_id=1 _item_id=2', $list);
    }
}
