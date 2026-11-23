<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Entity\Ulid;
use FakeVendor\HelloWorld\Resource\App\Rows\Ca;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

use function assert;
use function dirname;

class DependenciesTest extends TestCase
{
    private ResourceInterface $resource;
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        static $injector;

        $namespace = 'FakeVendor\HelloWorld';
        $module = new DevEtagModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if (! $injector) {
            $injector = new Injector($module, __DIR__ . '/tmp');
        }

        assert($injector instanceof InjectorInterface);
        $this->injector = $injector;
        $this->resource = $injector->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testSetSurrogateHeader(): void
    {
        $uri = new Uri('app://self/foo');
        $etags = $this->dependencies($uri);
        $foo1 = new class extends ResourceObject{
            /** @var array<string, string> */
            public $headers = [Header::SURROGATE_KEY => 'a b']; // phpcs:ignore
        };
        $foo1->uri = new Uri('app://self/foo1');
        $foo2 = new class extends ResourceObject{
            /** @var array<string, string> */
            public $headers = [Header::SURROGATE_KEY => 'a b']; // phpcs:ignore
        };
        $foo2->uri = new Uri('app://self/foo2');
        $etags->hole($foo1);
        $etags->hole($foo2);
        $ro = new class extends ResourceObject{
        };
        $ro->uri = $uri;
        $etags->addTo($ro);
        $this->assertSame('_foo_ _foo1_ grp-_foo1_ a b _foo2_ grp-_foo2_', $ro->headers[Header::SURROGATE_KEY]);

        $ro = $this->resource->get('page://self/html/page-surrogate-key');
        $this->assertStringContainsString('page-tag', $ro->headers[Header::SURROGATE_KEY]);
    }

    public function testOnePurgeKey(): void
    {
        $uri = new Uri('app://self/foo');
        $etags = $this->dependencies($uri);
        $foo = new class extends ResourceObject{
        };
        $foo->uri = $uri;
        $etags->hole($foo);
        $etags->addTo($foo);
        $this->assertSame('_foo_ grp-_foo_', $foo->headers[Header::SURROGATE_KEY]);
    }

    public function testSetSurrogateHeaderDeduplicatesWithManualKeys(): void
    {
        $uri = new Uri('app://self/foo');
        $etags = $this->dependencies($uri);
        $child = new class extends ResourceObject{
            /** @var array<string, string> */
            public $headers = [Header::SURROGATE_KEY => 'shared-tag']; // phpcs:ignore
        };
        $child->uri = new Uri('app://self/foo1');
        $etags->hole($child);
        $ro = new class extends ResourceObject{
            /** @var array<string, string> */
            public $headers = [Header::SURROGATE_KEY => 'shared-tag _foo_']; // phpcs:ignore
        };
        $ro->uri = $uri;
        $etags->addTo($ro);
        $this->assertSame('shared-tag _foo_ _foo1_ grp-_foo1_', $ro->headers[Header::SURROGATE_KEY]);
    }

    public function testEmbeddedRequestsThatDifferOnlyInStringableQueryValuesKeepTheirOwnDeclarations(): void
    {
        $keys = $this->dependencies(new Uri('page://self/page'));
        foreach (['c1', 'c2'] as $channelId) {
            $child = new Ca();
            $child->uri = new Uri('app://self/rows/ca', ['channelId' => new Ulid($channelId)]);
            $child->body = [];
            $keys->embedded($child);
            $this->assertSame((new UriTag())(new Uri('app://self/rows/taxonomy', ['ulid' => $channelId])), $child->headers[Header::SURROGATE_KEY]);
        }
    }

    private function dependencies(AbstractUri $uri): Dependencies
    {
        return $this->injector->getInstance(CacheDependencyCollector::class)->begin(new TagSet([(new UriTag())($uri)]));
    }
}
