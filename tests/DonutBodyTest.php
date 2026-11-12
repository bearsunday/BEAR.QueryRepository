<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\Counter;
use FakeVendor\HelloWorld\Resource\App\FlakyHole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function explode;
use function json_decode;
use function restore_error_handler;
use function set_error_handler;

use const JSON_THROW_ON_ERROR;

/**
 * A renderer that encodes the body as JSON prints no placeholder for a hole
 *
 * - the page shows each hole as it does without the donut, and takes the hole's tags
 * - a hit reads the holes again and does not run the page
 */
class DonutBodyTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        Counter::$calls = 0;
        $this->resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
    }

    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield '#[DonutCache]' => ['page://self/html/blog-posting-donut'];
        yield '#[CacheableResponse]' => ['page://self/html/blog-posting'];
    }

    #[DataProvider('pages')]
    public function testTheHoleIsShownAndItsTagsReachThePage(string $uri): void
    {
        foreach (['miss', 'hit'] as $read) {
            $ro = $this->resource->get($uri);

            $this->assertSame(['comment' => ['comment' => 'comment01'], 'article' => '1'], json_decode($ro->toString(), true, 512, JSON_THROW_ON_ERROR), $read);
            $this->assertContains('comment01', explode(' ', $ro->headers['Surrogate-Key'] ?? ''), $read);
        }
    }

    public function testAHitReadsTheHoleAgainWithoutRunningThePage(): void
    {
        $miss = json_decode($this->resource->get('page://self/html/counter-donut')->toString(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, Counter::$calls, 'read in onGet() and encoded by the renderer: one run');

        $hit = json_decode($this->resource->get('page://self/html/counter-donut')->toString(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['label' => 'counter-donut', 'counter' => ['call' => 1, 'tag' => null], 'read' => 1], $miss);
        $this->assertSame(['label' => 'counter-donut', 'counter' => ['call' => 2, 'tag' => null], 'read' => 1], $hit);
    }

    public function testAHoleThatFailsOnAHitFallsBackToAFreshRender(): void
    {
        $uri = 'page://self/html/donut-cache-with-flaky-hole';
        FlakyHole::$failureCount = 0;
        $this->resource->get($uri);

        FlakyHole::$failureCount = 1;
        set_error_handler(static fn (): bool => true); // the fallback warns
        try {
            $hit = $this->resource->get($uri);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $hit->code);
        $this->assertSame(['flaky' => ['value' => 'healthy']], json_decode($hit->toString(), true, 512, JSON_THROW_ON_ERROR));
    }
}
