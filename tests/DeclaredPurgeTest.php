<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\TagPage;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

/** A #[Purge] written with strings reaches the entries it names */
class DeclaredPurgeTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        TagPage::$version = 1;

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
}
