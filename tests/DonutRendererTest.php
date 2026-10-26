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
     * A long-running container reuses one DonutRenderer across requests: a second MISS of
     * the same page must overwrite the hole's view by placeholder, not append behind it -
     * str_replace() with a duplicate search entry only ever consumes the FIRST occurrence
     * in the template, so an appended, stale second entry would never apply.
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
