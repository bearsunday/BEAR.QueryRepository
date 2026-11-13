<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\JsonRenderer;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Closure;
use FakeVendor\HelloWorld\Resource\App\Counter;
use FakeVendor\HelloWorld\Resource\App\FlakyHole;
use FakeVendor\HelloWorld\Resource\App\UnbuildableHole;
use FakeVendor\HelloWorld\Resource\Page\Html\RunCountingFlakyDonut;
use FakeVendor\HelloWorld\Resource\Page\Html\RunCountingFlakyResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function explode;
use function json_decode;
use function restore_error_handler;
use function set_error_handler;

use const JSON_THROW_ON_ERROR;

/**
 * A renderer that encodes the body as JSON prints no placeholder for an embedded request
 *
 * - the page shows each embedded request as it does without the donut, and takes its tags
 * - a hit reads them again and does not run the page
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
    public function testAnEmbeddedRequestIsShownAndItsTagsReachThePage(string $uri): void
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

    /** @return iterable<string, array{string, Closure(): void}> */
    public static function holesThatFailOnAHit(): iterable
    {
        yield 'the hole fails to run' => [
            'page://self/html/run-counting-flaky-donut',
            static function (): void {
                FlakyHole::$failureCount = 1;
            },
        ];

        yield 'the hole cannot be built' => [
            'page://self/html/run-counting-unbuildable-donut',
            static function (): void {
                UnbuildableHole::$failureCount = 1;
            },
        ];
    }

    /** @param Closure(): void $failNextTime */
    #[DataProvider('holesThatFailOnAHit')]
    public function testAHoleThatFailsOnAHitFallsBackToAFreshRunOfThePage(string $uri, Closure $failNextTime): void
    {
        RunCountingFlakyDonut::$runs = 0;
        FlakyHole::$failureCount = 0;
        UnbuildableHole::$failureCount = 0;
        $this->resource->get($uri);

        $failNextTime();
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

    public function testAHitThatCannotStoreThePageAgainFallsBackToAFreshRunOfThePage(): void
    {
        $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refuseSave: false);
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class ($pool) extends AbstractModule {
            public function __construct(private TagAwareAdapterInterface $pool)
            {
                parent::__construct();
            }

            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($this->pool);
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $uri = 'page://self/html/run-counting-flaky-response';
        RunCountingFlakyResponse::$runs = 0;
        FlakyHole::$failureCount = 0;
        $resource->get($uri);
        // The view goes, the page's donut stays: the next read rebuilds the view and stores it again
        $injector->getInstance(QueryRepositoryInterface::class)->purge(new Uri('app://self/flaky-hole'));

        $pool->throwOnSave = true;
        set_error_handler(static fn (): bool => true); // the fallback and its store warn
        try {
            $failedHit = $resource->get($uri);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(200, $failedHit->code);
        $this->assertSame(['flaky' => ['value' => 'healthy'], 'run' => 2], json_decode($failedHit->toString(), true, 512, JSON_THROW_ON_ERROR), 'onGet() ran on the resource as it came in, not on the rebuilt body');
    }

    public function testARequestWhoseResultComesOnlyFromInvokingItRunsOnceAndGivesItsTags(): void
    {
        $child = new class extends ResourceObject {
        };
        $child->uri = new Uri('app://self/deferred');
        $child->body = ['hole' => 1];
        $child->headers[Header::SURROGATE_KEY] = 'hole-tag';
        $request = new FakeDeferredRequest($child);
        $page = self::jsonPage(['hole' => $request]);
        $keys = new SurrogateKeys($page->uri);

        ResourceDonut::create($page, new DonutRenderer(), $keys, null, false);
        $keys->setSurrogateHeader($page);

        $this->assertSame(1, $request->runs);
        $this->assertSame(['hole' => ['hole' => 1]], json_decode((string) $page->view, true, 512, JSON_THROW_ON_ERROR));
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
