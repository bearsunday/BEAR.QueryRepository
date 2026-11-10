<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnBodyNotRows;
use BEAR\QueryRepository\Exception\DependsOnRowKeyNotExists;
use BEAR\QueryRepository\Exception\DependsOnRowValueNotScalar;
use BEAR\QueryRepository\Exception\DependsOnTemplateWithoutVariable;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\Rows\Ca;
use FakeVendor\HelloWorld\Resource\App\Rows\Cr;
use FakeVendor\HelloWorld\Resource\App\Rows\Mr;
use FakeVendor\HelloWorld\Resource\App\Rows\PageDonut;
use FakeVendor\HelloWorld\Resource\App\Rows\PageHole;
use FakeVendor\HelloWorld\Resource\App\Rows\PageValue;
use FakeVendor\HelloWorld\Resource\App\Rows\PageView;
use FakeVendor\HelloWorld\Resource\App\Rows\Plain;
use FakeVendor\HelloWorld\Resource\App\Rows\Ps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Throwable;

/** A write to a row that #[DependsOn] names invalidates the list and every page that embeds it */
class DependsOnTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);

        Ca::$runs = 0;
        Ca::$data = ['1' => ['1', '3'], '2' => ['7']];
        Cr::$data = ['1' => ['1', '3'], '2' => ['7']];
        Mr::$data = ['1' => ['1', '3'], '2' => ['7']];
        Ps::$data = ['1' => ['1', '3'], '2' => ['7']];
        Plain::$data = ['1' => ['1', '3'], 'empty' => []];
        PageValue::$runs = 0;
        PageView::$runs = 0;
        PageDonut::$runs = 0;
        PageHole::$runs = 0;

        parent::setUp();
    }

    /** @return array<string, array{0: string, 1: bool}> uri, whether the entry is donut-stored (vs. a plain value) */
    public static function declarationShapeProvider(): array
    {
        return [
            'class #[Cacheable]' => ['app://self/rows/ca?article_id=1', false],
            'class #[CacheableResponse]' => ['app://self/rows/cr?article_id=1', true],
            'method #[CacheableResponse]' => ['app://self/rows/mr?article_id=1', true],
            'onGet() putStatic()' => ['app://self/rows/ps?article_id=1', true],
        ];
    }

    /** A write to one of its rows invalidates the list, across every declaration shape */
    #[DataProvider('declarationShapeProvider')]
    public function testDependsOnInvalidatesTheListForEachDeclarationShape(string $uri, bool $isDonut): void
    {
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, $isDonut), "{$uri} must be stored after the first GET");

        $this->resource->put('app://self/rows/comment?id=7');
        $this->assertTrue($this->isStored($uri, $isDonut), 'a write to an unrelated id must not invalidate the list');

        $this->resource->put('app://self/rows/comment?id=3');
        $this->assertFalse($this->isStored($uri, $isDonut), "a write to one of the list's own rows must invalidate it");
    }

    /** @return array<string, array{0: string, 1: bool}> page uri, whether the entry is donut-stored */
    public static function embeddingPageProvider(): array
    {
        return [
            '#[Cacheable] page' => ['app://self/rows/page-value', false],
            'class #[CacheableResponse] page' => ['app://self/rows/page-view', false],
            'method #[CacheableResponse] page' => ['app://self/rows/page-donut', true],
        ];
    }

    /** A page that embeds a declared, uncached list is invalidated by a write to one of the list's rows */
    #[DataProvider('embeddingPageProvider')]
    public function testEmbeddingPropagatesTheDependencyToTheParentPage(string $uri, bool $isDonut): void
    {
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, $isDonut), "{$uri} must be stored after the first GET");

        $this->resource->put('app://self/rows/comment?id=7');
        $this->assertTrue($this->isStored($uri, $isDonut), 'a write to an unrelated id must not invalidate the page');

        $this->resource->put('app://self/rows/comment?id=3');
        $this->assertFalse($this->isStored($uri, $isDonut), "a write to the embedded list's own row must invalidate the page");
    }

    /** A hand-set Surrogate-Key and the declared tag both survive, and either one's invalidation drops the entry */
    public function testHandWrittenKeyAndDeclaredTagBothInvalidateTheEntry(): void
    {
        $uri = 'app://self/rows/hand-key';
        $this->resource->get($uri);
        $this->assertNotNull($this->repository->get(new Uri($uri)));

        $this->storage->invalidateTags(['hand-key']);
        $this->assertNull($this->repository->get(new Uri($uri)), 'invalidating the hand-written key must drop the entry');

        $this->resource->get($uri);
        $this->assertNotNull($this->repository->get(new Uri($uri)));

        $this->resource->put('app://self/rows/comment?id=5');
        $this->assertNull($this->repository->get(new Uri($uri)), "invalidating the declared row's tag must also drop the entry");
    }

    /** @return array<string, array{0: string, 1: class-string<Throwable>}> */
    public static function invalidBodyProvider(): array
    {
        return [
            'body is not a list' => ['app://self/rows/keyed-body', DependsOnBodyNotRows::class],
            'row is a scalar, not an array' => ['app://self/rows/scalar-row', DependsOnBodyNotRows::class],
            'row has no key the template needs' => ['app://self/rows/missing-key', DependsOnRowKeyNotExists::class],
            'template has no variable' => ['app://self/rows/no-variable-template', DependsOnTemplateWithoutVariable::class],
            "row's value is an array, not a scalar" => ['app://self/rows/array-value-row', DependsOnRowValueNotScalar::class],
        ];
    }

    /**
     * A body shape #[DependsOn] cannot read throws the exception that names the mismatch, and nothing is stored
     *
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('invalidBodyProvider')]
    public function testInvalidBodyShapeThrowsAndStoresNothing(string $uri, string $exception): void
    {
        $this->expectException($exception);

        try {
            $this->resource->get($uri);
        } finally {
            $this->assertNull($this->storage->get(new Uri($uri)), 'a failed declaration must not store a stale entry');
        }
    }

    /** A row whose declared value is null names no dependency for that row, but is otherwise stored normally */
    public function testANullRowIsSkippedNotAnError(): void
    {
        $uri = 'app://self/rows/null-row';
        $this->resource->get($uri);
        $this->assertNotNull($this->repository->get(new Uri($uri)));

        $this->resource->put('app://self/rows/comment?id=1');
        $this->assertNull($this->repository->get(new Uri($uri)), "a write to the first (non-null) row's id must invalidate the entry");

        $this->resource->get($uri);
        $this->resource->put('app://self/rows/comment?id=3');
        $this->assertNull($this->repository->get(new Uri($uri)), 'a write to the third row\'s id must also invalidate the entry');
    }

    /** A row keyed differently from the row resource's argument reaches it through the template's mapping */
    public function testATemplateMapsARowKeyToTheRowArgument(): void
    {
        $uri = 'app://self/rows/renamed';
        $this->resource->get($uri);
        $this->assertNotNull($this->repository->get(new Uri($uri)));

        $this->resource->put('app://self/rows/comment?id=7');
        $this->assertNotNull($this->repository->get(new Uri($uri)), 'a write to an unrelated id must not invalidate the list');

        $this->resource->put('app://self/rows/comment?id=3');
        $this->assertNull($this->repository->get(new Uri($uri)), "a write to the mapped row's id must invalidate the list");
    }

    /** A row added by a POST becomes a dependency once the list is refetched and re-tagged */
    public function testARowAddedByPostBecomesADependencyAfterTheListIsRefetched(): void
    {
        $uri = 'app://self/rows/ca?article_id=1';
        $this->resource->get($uri);
        $this->assertSame(1, Ca::$runs);

        // Ca is #[Cacheable]: writing to it purges and refetches its own entry immediately.
        $this->resource->post('app://self/rows/ca', ['article_id' => '1', 'id' => '9']);
        $this->assertSame(2, Ca::$runs, 'the POST must have refreshed the list, tagging the new row');

        $this->resource->put('app://self/rows/comment?id=9');
        $this->assertNull($this->repository->get(new Uri($uri)), 'a write to the newly added row must invalidate the refreshed list');
    }

    /** A declared list with zero rows for this call is still a dependency: its own purge invalidates the embedding page */
    public function testADeclaredEmptyListIsStillADependencyOfItsPage(): void
    {
        $pageUri = 'app://self/rows/page-empty-rows';
        $this->resource->get($pageUri);
        $this->assertNotNull($this->repository->get(new Uri($pageUri)));

        $this->repository->purge(new Uri('app://self/rows/plain?article_id=empty'));
        $this->assertNull($this->repository->get(new Uri($pageUri)), "purging the empty list's own URI must invalidate the page that declared a dependency on it");
    }

    /**
     * A declared but uncached list rendered as a donut hole (never read in onGet()) does not become a page
     * dependency: applying #[DependsOn] to a child must not turn a hole into a material.
     */
    public function testADeclaredListRenderedAsAHoleDoesNotBecomeAPageDependency(): void
    {
        $pageUri = 'app://self/rows/page-hole';
        $this->resource->get($pageUri);
        $this->assertSame(1, PageHole::$runs);
        $this->assertNotNull($this->repository->get(new Uri($pageUri)));

        $this->resource->put('app://self/rows/comment?id=3');
        $this->assertNotNull($this->repository->get(new Uri($pageUri)), "a write to the hole's row must not invalidate the page: the hole was never read, so it was never declared");

        $this->resource->get($pageUri);
        $this->assertSame(1, PageHole::$runs, 'the page must still answer from cache, not have re-run onGet()');
    }

    private function isStored(string $uri, bool $isDonut): bool
    {
        $abstractUri = new Uri($uri);

        return $isDonut ? $this->storage->getDonut($abstractUri) !== null : $this->repository->get($abstractUri) !== null;
    }
}
