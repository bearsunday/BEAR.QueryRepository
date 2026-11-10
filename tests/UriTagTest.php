<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;

class UriTagTest extends TestCase
{
    public function testScalarQueryTag(): void
    {
        $uri = new Uri('app://self/article');
        $uri->query = ['ulid' => '01ABC', 'page' => 1];

        $this->assertSame('_article_page=1_ulid=01ABC', (new UriTag())($uri));
    }

    public function testStringableValueIsTaggedAsItsString(): void
    {
        $uri = new Uri('app://self/article');
        $uri->query = ['ulid' => new FakeStringableId('01ABC'), 'page' => 1];

        $this->assertSame('_article_page=1_ulid=01ABC', (new UriTag())($uri));
    }

    public function testStringableInsideAnArrayIsTaggedAsItsString(): void
    {
        $uri = new Uri('app://self/article');
        $uri->query = ['ids' => [new FakeStringableId('a'), 'b']];
        $expected = new Uri('app://self/article');
        $expected->query = ['ids' => ['a', 'b']];

        $this->assertSame((new UriTag())($expected), (new UriTag())($uri));
    }
}
