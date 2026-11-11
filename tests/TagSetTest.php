<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

use function explode;

class TagSetTest extends TestCase
{
    public function testKeepsFirstOccurrenceOrderAndRemovesOnlyEmptyStrings(): void
    {
        $tags = new TagSet(['b', '', 'a', 'b', '0', 'a', ' ']);

        $this->assertSame(['b', 'a', '0', ' '], $tags->toArray());
        $this->assertSame('b a 0  ', $tags->toHeader());
    }

    public function testSpaceSplitHeaderKeepsOtherWhitespaceAndDropsEmptyKeys(): void
    {
        $this->assertSame(["a\tb", 'c'], (new TagSet(explode(' ', " a\tb  c ")))->toArray());
        $this->assertSame([], (new TagSet(explode(' ', '')))->toArray());
    }

    public function testTemplateViewAndResponseCanKeepSeparateOrders(): void
    {
        $template = new TagSet(['self', 'grp-self', 'material']);
        $view = $template->merge(new TagSet(['hole', 'material']));
        $response = (new TagSet(['declared']))->merge($view);

        $this->assertSame(['self', 'grp-self', 'material'], $template->toArray());
        $this->assertSame(['self', 'grp-self', 'material', 'hole'], $view->toArray());
        $this->assertSame('declared self grp-self material hole', $response->toHeader());
    }
}
