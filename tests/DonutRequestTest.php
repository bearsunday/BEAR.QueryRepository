<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

class DonutRequestTest extends TestCase
{
    private AbstractRequest $request;
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, $_ENV['TMP_DIR']);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        /** @var AbstractRequest $request */
        $request = $this->resource->get->uri('page://self/html/comment'); // @phpstan-ignore-line
        $this->request = $request;

        parent::setUp();
    }

    public function testToString(): DonutRenderer
    {
        $storage = new DonutRenderer();
        $request = new DonutRequest($this->request, $storage, new SurrogateKeys(new Uri('app://self/foo')));
        $view = (string) $request;
        $this->assertSame('[le:page://self/html/comment]', $view);

        return $storage;
    }

    /** @depends testToString */
    public function testRender(DonutRenderer $storage): void
    {
        $view = $storage->render('cmt=[le:page://self/html/comment]');
        $this->assertSame('cmt=comment01', $view);
    }
}
