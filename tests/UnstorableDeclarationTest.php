<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableDeclaration;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

class UnstorableDeclarationTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $this->resource = (new Injector($module, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
    }

    /** @return iterable<string, array{string, string}> */
    public static function storedWithAnUnstorableDeclaration(): iterable
    {
        yield 'Cacheable, isPrivate' => ['app://self/http-cache-control-with-cacheable', 'private'];
        yield 'Cacheable, NoHttpCache' => ['app://self/cacheable-no-http-cache', 'private, no-store, no-cache, must-revalidate'];
        yield 'CacheableResponse, isPrivate' => ['page://self/html/cacheable-response-private', 'private'];
        yield 'CacheableResponse, noStore' => ['page://self/html/cacheable-response-no-store', 'no-store'];
        yield 'CacheableResponse, NoHttpCache' => ['page://self/html/cacheable-response-no-http-cache', 'private, no-store, no-cache, must-revalidate'];
        yield 'DonutCache, isPrivate' => ['page://self/html/donut-cache-private', 'private'];
        yield 'putStatic() in onGet(), isPrivate' => ['page://self/html/blog-posting-declared-private', 'private'];
    }

    #[DataProvider('storedWithAnUnstorableDeclaration')]
    public function testEveryGetThrowsAsNothingIsStored(string $uri, string $declared): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                $this->resource->get($uri);
                $this->fail("GET {$attempt} was answered");
            } catch (UnstorableDeclaration $e) {
                $this->assertSame($uri, $e->uri);
                $this->assertSame($declared, $e->cacheControl);
            }
        }
    }
}
