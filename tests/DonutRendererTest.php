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
        $template = sprintf('template foo=%s, bar=%s', sprintf(ResourceDonut::FOMRAT, 'app://foo'), sprintf(ResourceDonut::FOMRAT, 'app://foo'));
        $view = $renderer->render($template);
        $this->assertSame('template foo=Foo, bar=Foo', $view);
    }
}
