<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\JsonRenderer;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\Counter;
use FakeVendor\HelloWorld\Resource\App\FlakyHole;
use FakeVendor\HelloWorld\Resource\Page\Html\RunCountingFlakyDonut;
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

    public function testAHoleThatFailsOnAHitFallsBackToAFreshRunOfThePage(): void
    {
        $uri = 'page://self/html/run-counting-flaky-donut';
        RunCountingFlakyDonut::$runs = 0;
        FlakyHole::$failureCount = 0;
        $this->resource->get($uri);

        FlakyHole::$failureCount = 1;
        set_error_handler(static fn (): bool => true); // the fallback warns
        try {
            $failedHit = $this->resource->get($uri);
        } finally {
            restore_error_handler();
        }

        $expected = ['flaky' => ['value' => 'healthy'], 'run' => 2];
        $this->assertSame(200, $failedHit->code);
        $this->assertSame($expected, json_decode($failedHit->toString(), true, 512, JSON_THROW_ON_ERROR), 'onGet() ran on the resource as it came in, not on the stored body');
        $this->assertSame($expected, json_decode($this->resource->get($uri)->toString(), true, 512, JSON_THROW_ON_ERROR), 'the fresh run is what is stored');
    }

    public function testAHoleThatIsNoRequestIsNotRunAgainForItsTags(): void
    {
        $child = new class extends ResourceObject {
        };
        $child->uri = new Uri('app://self/non-request');
        $child->headers[Header::SURROGATE_KEY] = 'hole-tag';
        $invoker = new class ($child) implements InvokerInterface {
            public int $calls = 0;

            public function __construct(private readonly ResourceObject $ro)
            {
            }

            public function invoke(AbstractRequest $request): ResourceObject
            {
                $this->calls++;

                return $this->ro;
            }
        };
        $page = self::jsonPage(['hole' => new FakeNonRequestHole($invoker, $child)]);
        $keys = new SurrogateKeys($page->uri);

        ResourceDonut::create($page, new DonutRenderer(), $keys, null, false);
        $keys->setSurrogateHeader($page);

        $this->assertSame(0, $invoker->calls, 'the renderer read the hole; asking it again would run it a second time');
        $this->assertContains('hole-tag', explode(' ', $page->headers[Header::SURROGATE_KEY] ?? ''));
    }

    public function testABodyThatCannotBeStoredIsRefused(): void
    {
        $page = self::jsonPage(['hole' => $this->resource->uri('page://self/html/comment'), 'handler' => static fn () => null]);

        $this->expectExceptionMessage("Serialization of 'Closure' is not allowed");

        ResourceDonut::create($page, new DonutRenderer(), new SurrogateKeys($page->uri), null, false);
    }

    /** @param array<string, mixed> $body */
    private static function jsonPage(array $body): ResourceObject
    {
        $page = new class extends ResourceObject {
        };
        $page->uri = new Uri('page://self/json-page');
        $page->body = $body;
        $page->setRenderer(new JsonRenderer());

        return $page;
    }
}
