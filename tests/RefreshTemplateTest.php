<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\TemplateLacksRequiredParameter;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\CacheablePagedCategoryWriter;
use FakeVendor\HelloWorld\Resource\App\PagedCategory;
use FakeVendor\HelloWorld\Resource\App\PagedCategoryWriter;
use FakeVendor\HelloWorld\Resource\App\PathTemplateWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/** A #[Purge]/#[Refresh] template must carry every required onGet() parameter of its destination */
class RefreshTemplateTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        PagedCategory::$version = 1;
        PagedCategoryWriter::$writes = 0;
        CacheablePagedCategoryWriter::$writes = 0;
        PathTemplateWriter::$writes = 0;

        parent::setUp();
    }

    /** @return array<string, array{string, string, class-string<PagedCategoryWriter|CacheablePagedCategoryWriter>, string}> */
    public static function lackingDeclarations(): array
    {
        return [
            'Purge via RefreshInterceptor' => ['app://self/paged-category-writer', 'patch', PagedCategoryWriter::class, 'Purge'],
            'Refresh via RefreshInterceptor' => ['app://self/paged-category-writer', 'put', PagedCategoryWriter::class, 'Refresh'],
            'Purge via CommandInterceptor' => ['app://self/cacheable-paged-category-writer', 'patch', CacheablePagedCategoryWriter::class, 'Purge'],
            'Refresh via CommandInterceptor' => ['app://self/cacheable-paged-category-writer', 'put', CacheablePagedCategoryWriter::class, 'Refresh'],
        ];
    }

    /** @param class-string<PagedCategoryWriter|CacheablePagedCategoryWriter> $writer */
    #[DataProvider('lackingDeclarations')]
    public function testTemplateLackingARequiredParameterStopsTheWrite(string $uri, string $method, string $writer, string $attribute): void
    {
        try {
            $this->resource->{$method}($uri, ['categoryId' => 1]);
            $this->fail('the write was not stopped');
        } catch (TemplateLacksRequiredParameter $e) {
            $this->assertSame(['page'], $e->missing);
            $this->assertSame("#[{$attribute}(uri: 'app://self/paged-category{?category_id}')]", $e->declaration);
        }

        $this->assertSame(0, $writer::$writes);
    }

    /** @return array<string, array{string, string, class-string<PagedCategoryWriter|CacheablePagedCategoryWriter>, string}> */
    public static function completeDeclarations(): array
    {
        return [
            'Purge with snake_case via RefreshInterceptor' => ['app://self/paged-category-writer', 'post', PagedCategoryWriter::class, 'category_id'],
            'Refresh with camelCase via RefreshInterceptor' => ['app://self/paged-category-writer', 'delete', PagedCategoryWriter::class, 'categoryId'],
            'Purge with snake_case via CommandInterceptor' => ['app://self/cacheable-paged-category-writer', 'post', CacheablePagedCategoryWriter::class, 'category_id'],
            'Refresh with camelCase via CommandInterceptor' => ['app://self/cacheable-paged-category-writer', 'delete', CacheablePagedCategoryWriter::class, 'categoryId'],
        ];
    }

    /**
     * The templates omit the default-valued perPage and still reach the page they name
     *
     * @param class-string<PagedCategoryWriter|CacheablePagedCategoryWriter> $writer
     * @param 'category_id'|'categoryId'                                     $categoryKey the spelling the template uses, so the entry carries the tag the write invalidates
     */
    #[DataProvider('completeDeclarations')]
    public function testCompleteTemplateInvalidatesTheNamedEntry(string $uri, string $method, string $writer, string $categoryKey): void
    {
        $this->resource->get('app://self/paged-category', [$categoryKey => 1, 'page' => 1]);
        $this->resource->get('app://self/paged-category', [$categoryKey => 1, 'page' => 2]);

        $this->resource->{$method}($uri, ['categoryId' => 1, 'page' => 2]);

        $this->assertSame(1, $writer::$writes);
        $this->assertSame(['v' => '1:1:20:v1'], $this->resource->get('app://self/paged-category', [$categoryKey => 1, 'page' => 1])->body);
        $this->assertSame(['v' => '1:2:20:v2'], $this->resource->get('app://self/paged-category', [$categoryKey => 1, 'page' => 2])->body);
    }

    public function testTemplateWithAPathVariableIsNotChecked(): void
    {
        $this->resource->put('app://self/path-template-writer', ['target' => 'paged-category']);

        $this->assertSame(1, PathTemplateWriter::$writes);
    }
}
