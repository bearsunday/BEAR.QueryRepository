<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function explode;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/** A hole's tags join the page's while the template renders it, and the log records that edge */
class DonutHoleDependsOnTest extends TestCase
{
    use SemanticLogTreeTrait;

    private const PAGE = 'page://self/html/variant-hole-parent';
    private const HOLE = 'app://self/optional-param?id=1&page=2';

    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->logger->flush();

        parent::setUp();
    }

    public function testRenderingAHoleRecordsItsEdgeWithTheTagsThePageCarries(): void
    {
        $ro = $this->resource->get(self::PAGE);
        $tree = $this->flushAndValidate($this->logger);

        $skipped = self::skippedChildrenOf($tree, self::PAGE);
        $this->assertCount(1, $skipped);
        $this->assertSame(['parent' => self::PAGE, 'child' => self::HOLE, 'reason' => 'no_body'], json_decode($skipped[0], true, 512, JSON_THROW_ON_ERROR));
        $this->assertHoleTagsReachTheViewAndTheResponse($tree, $ro->headers[Header::SURROGATE_KEY]);
    }

    public function testRefreshingAHoleRecordsItsEdgeWithTheTagsThePageCarries(): void
    {
        $this->resource->get(self::PAGE);
        $this->repository->purge(new Uri(self::HOLE));
        $this->logger->flush();

        $ro = $this->resource->get(self::PAGE);
        $tree = $this->flushAndValidate($this->logger);

        $this->assertCount(1, self::eventContextsJsonOf($tree, 'refresh_donut'));
        $this->assertHoleTagsReachTheViewAndTheResponse($tree, $ro->headers[Header::SURROGATE_KEY]);
    }

    /** @param array<string, mixed> $tree */
    private function assertHoleTagsReachTheViewAndTheResponse(array $tree, string $surrogateKey): void
    {
        $edges = array_values(array_filter(
            array_map(static fn (string $json): array => (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR), self::eventContextsJsonOf($tree, 'depends_on')),
            static fn (array $edge): bool => $edge['child'] === self::HOLE,
        ));
        $this->assertCount(1, $edges);
        $this->assertSame(self::PAGE, $edges[0]['parent']);
        /** @var list<string> $childTags */
        $childTags = $edges[0]['childTags'];
        $this->assertSame(['_optional-param_id=1_page=2', 'grp-_optional-param_id=1'], $childTags);

        $views = self::eventContextsJsonOf($tree, 'save_donut_view', self::PAGE);
        $this->assertCount(1, $views);
        /** @var array{tags: list<string>} $view */
        $view = json_decode($views[0], true, 512, JSON_THROW_ON_ERROR);
        $viewTags = $view['tags'];
        $this->assertSame([], array_diff($childTags, $viewTags));
        $this->assertSame([], array_diff($childTags, explode(' ', $surrogateKey)));
    }
}
