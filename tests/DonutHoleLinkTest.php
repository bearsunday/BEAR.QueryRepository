<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\RenderInterface;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\Page\Html\LinkedHoleDonut;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

/** A hit follows the links of the request a hole was: a link changes what the hole shows */
class DonutHoleLinkTest extends TestCase
{
    /** @return iterable<string, array{bool, string}> whether the renderer prints the hole or encodes it, the link */
    public static function holes(): iterable
    {
        yield 'encoded, linkSelf()' => [false, 'self'];
        yield 'encoded, linkNew()' => [false, 'new'];
        yield 'encoded, linkCrawl()' => [false, 'crawl'];
        yield 'printed, linkSelf()' => [true, 'self'];
    }

    #[DataProvider('holes')]
    public function testAHitShowsTheLinkedResourceAsTheMissDid(bool $printed, string $link): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        if ($printed) {
            $module->override(new class extends AbstractModule {
                protected function configure(): void
                {
                    $this->bind(RenderInterface::class)->to(FakeCastingRenderer::class);
                }
            });
        }

        $resource = (new Injector($module, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
        LinkedHoleDonut::$runs = 0;

        $miss = $resource->get('page://self/html/linked-hole-donut', ['link' => $link])->toString();
        $hit = $resource->get('page://self/html/linked-hole-donut', ['link' => $link])->toString();

        $this->assertMatchesRegularExpression('/blog\W+12/', $miss, 'the hole shows the blog the author links to');
        $this->assertSame($miss, $hit);
        $this->assertSame(1, LinkedHoleDonut::$runs, 'the hit read the hole; a hole that fails falls back to running the page again');
    }
}
