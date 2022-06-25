<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

class DonutCacheTest extends TestCase
{
    private \BEAR\Resource\ResourceInterface $resource;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, $_ENV['TMP_DIR']);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        parent::setUp();
    }

    public function testGetState(): void
    {
        $donut = new ResourceDonut('cmt=[le:page://self/html/comment]', null, true);
        $blog = $this->resource->get('page://self/html/blog-posting');
        $ro = $donut->refresh($this->resource, $blog);
        $this->assertInstanceOf(ResourceObject::class, $ro);
        $this->assertSame('cmt=comment01', $ro->view);
    }
}
