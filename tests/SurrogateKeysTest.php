<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

class SurrogateKeysTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new DevEtagModule((new FakeEtagPoolModule(ModuleFactory::getInstance($namespace))));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, $_ENV['TMP_DIR']);
        }

        $this->resource = $injector->getInstance(ResourceInterface::class);
        parent::setUp();
    }

    public function testSetSurrogateHeader(): void
    {
        $uri = new Uri('app://self/foo');
        $etags = new SurrogateKeys($uri);
        $foo1 = new class extends ResourceObject{
            /** @var array<string, string> */
            public array $headers = [Header::SURROGATE_KEY => 'a b'];
        };
        $foo1->uri = new Uri('app://self/foo1');
        $foo2 = new class extends ResourceObject{
            /** @var array<string, string> */
            public array $headers = [Header::SURROGATE_KEY => 'a b'];
        };
        $foo2->uri = new Uri('app://self/foo2');
        $etags->addTag($foo1);
        $etags->addTag($foo2);
        $ro = new class extends ResourceObject{
        };
        $ro->uri = $uri;
        $etags->setSurrogateHeader($ro);
        $this->assertSame('_foo_ _foo1_ a b _foo2_', $ro->headers[Header::SURROGATE_KEY]);

        $ro = $this->resource->get('page://self/html/page-surrogate-key');
        $this->assertStringContainsString('page-tag', $ro->headers[Header::SURROGATE_KEY]);
    }

    public function testOnePurgeKey(): void
    {
        $uri = new Uri('app://self/foo');
        $etags = new SurrogateKeys($uri);
        $foo = new class extends ResourceObject{
        };
        $foo->uri = $uri;
        $etags->addTag($foo);
        $etags->setSurrogateHeader($foo);
        $this->assertSame('_foo_', $foo->headers[Header::SURROGATE_KEY]);
    }
}
