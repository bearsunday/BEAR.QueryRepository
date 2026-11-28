<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

use function sprintf;

class DonutRendererTest extends TestCase
{
    public function testRender(): void
    {
        $renderer = new DonutRenderer();
        $renderer->setView('app://foo', 'Foo');
        $renderer->setView('app://bar', 'Bar');
        $template = sprintf('template foo=%s, bar=%s', ResourceDonut::placeholder('app://foo'), ResourceDonut::placeholder('app://bar'));
        $view = $renderer->render($template);
        $this->assertSame('template foo=Foo, bar=Bar', $view);
    }

    /**
     * When one DonutRenderer receives the same placeholder twice, the later view must win:
     * str_replace() with duplicate search entries consumes the first occurrence, whereas
     * strtr() keyed by placeholder replaces it.
     */
    public function testSetViewOverwritesAnEarlierViewForTheSamePlaceholder(): void
    {
        $renderer = new DonutRenderer();
        $renderer->setView('app://foo', 'stale');
        $template = sprintf('template foo=%s', ResourceDonut::placeholder('app://foo'));

        $renderer->setView('app://foo', 'fresh');

        $this->assertSame('template foo=fresh', $renderer->render($template));
    }
}
