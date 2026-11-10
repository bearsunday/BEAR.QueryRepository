<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\ArgumentPurgeWriter;
use FakeVendor\HelloWorld\Resource\App\FanOutWriter;
use FakeVendor\HelloWorld\Resource\App\PagedCategory;
use FakeVendor\HelloWorld\Resource\App\TagPage;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/** A #[Purge] written with strings reaches the entries it names */
class DeclaredPurgeTest extends TestCase
{
    private Injector $injector;
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $this->injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $this->injector->getInstance(ResourceInterface::class);
        TagPage::$version = 1;
        PagedCategory::$version = 1;

        parent::setUp();
    }

    public function testEntryFetchedWithAStringableIsPurgedByItsString(): void
    {
        $this->resource->get('app://self/tag-page', ['id' => new FakeStringableId('a')]);
        $this->resource->get('app://self/tag-page', ['id' => 'b']);

        $this->resource->post('app://self/fan-out-writer', ['id' => 'a']);

        $this->assertSame(['v' => 'a:1:v2'], $this->resource->get('app://self/tag-page', ['id' => new FakeStringableId('a')])->body);
        $this->assertSame(['v' => 'b:1:v1'], $this->resource->get('app://self/tag-page', ['id' => 'b'])->body);
    }

    public function testStringablesOfDifferentValuesKeepTheirOwnEntries(): void
    {
        $this->resource->get('app://self/tag-page', ['id' => new FakeStringableId('a')]);

        $this->assertSame(['v' => 'b:1:v1'], $this->resource->get('app://self/tag-page', ['id' => new FakeStringableId('b')])->body);
    }

    public function testWriteCalledWithoutAUriPurgesFromItsBody(): void
    {
        $this->resource->get('app://self/tag-page', ['id' => 'a']);
        $this->resource->get('app://self/tag-page', ['id' => 'b']);
        $writer = $this->injector->getInstance(FanOutWriter::class);

        $writer->onPost('a');

        $this->assertSame(['v' => 'a:1:v2'], $this->resource->get('app://self/tag-page', ['id' => 'a'])->body);
        $this->assertSame(['v' => 'b:1:v1'], $this->resource->get('app://self/tag-page', ['id' => 'b'])->body);
    }

    public function testWriteCalledWithoutAUriPurgesFromItsArguments(): void
    {
        $this->resource->get('app://self/tag-page', ['id' => 'a']);
        $this->resource->get('app://self/tag-page', ['id' => 'b']);

        $this->injector->getInstance(ArgumentPurgeWriter::class)->onPost('a');

        $this->assertSame(['v' => 'a:1:v2'], $this->resource->get('app://self/tag-page', ['id' => 'a'])->body);
        $this->assertSame(['v' => 'b:1:v1'], $this->resource->get('app://self/tag-page', ['id' => 'b'])->body);
    }

    public function testOmittedDefaultArgumentIsLeftOutAsARequestLeavesItOut(): void
    {
        $this->resource->get('app://self/tag-page', ['id' => 'a', 'page' => 2]);
        $this->resource->get('app://self/tag-page', ['id' => 'b']);

        $this->injector->getInstance(ArgumentPurgeWriter::class)->onPut('a');

        $this->assertSame(['v' => 'a:2:v2'], $this->resource->get('app://self/tag-page', ['id' => 'a', 'page' => 2])->body);
        $this->assertSame(['v' => 'b:1:v1'], $this->resource->get('app://self/tag-page', ['id' => 'b'])->body);
    }

    public function testArgumentReachesASnakeCaseTemplateVariable(): void
    {
        $this->resource->get('app://self/paged-category', ['category_id' => 1, 'page' => 1]);
        $this->resource->get('app://self/paged-category', ['category_id' => 1, 'page' => 2]);

        $this->injector->getInstance(ArgumentPurgeWriter::class)->onPatch(1, 2);

        $this->assertSame(['v' => '1:1:20:v1'], $this->resource->get('app://self/paged-category', ['category_id' => 1, 'page' => 1])->body);
        $this->assertSame(['v' => '1:2:20:v2'], $this->resource->get('app://self/paged-category', ['category_id' => 1, 'page' => 2])->body);
    }
}
