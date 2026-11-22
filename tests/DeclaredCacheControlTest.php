<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableDeclaration;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Resource\App\HttpCacheControlWithCacheable;
use FakeVendor\HelloWorld\Resource\App\NoHttpCacheControl;
use FakeVendor\HelloWorld\Resource\Page\Html\CacheableResponseNoStore;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class DeclaredCacheControlTest extends TestCase
{
    private DeclaredCacheControl $declaredCacheControl;

    protected function setUp(): void
    {
        $injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $this->declaredCacheControl = $injector->getInstance(DeclaredCacheControl::class);
    }

    public function testNoCacheControlIsShared(): void
    {
        $this->assertSame(Storability::Shared, $this->declaredCacheControl->storability(new None()));
    }

    public function testCacheControlNoStoreIsUnstorable(): void
    {
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'no-store';

        $this->assertSame(Storability::Unstorable, $this->declaredCacheControl->storability($ro));
    }

    public function testCacheControlPrivateIsUnstorable(): void
    {
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'PRIVATE';

        $this->assertSame(Storability::Unstorable, $this->declaredCacheControl->storability($ro), 'RFC 9111 directives are case-insensitive');
    }

    public function testCacheControlHeaderNameIsMatchedCaseInsensitively(): void
    {
        // $ro->headers is a plain PHP array, not an HTTP-aware collection - 'cache-control'
        // must be found the same way 'Cache-Control' is (RFC 7230 §3.2).
        $ro = new None();
        $ro->headers['cache-control'] = 'no-store';

        $this->assertSame(Storability::Unstorable, $this->declaredCacheControl->storability($ro));
    }

    /** @return iterable<string, array{ResourceObject, string}> */
    public static function unstorableDeclarations(): iterable
    {
        yield 'isPrivate' => [new HttpCacheControlWithCacheable(), 'private'];
        yield 'NoHttpCache' => [new NoHttpCacheControl(), 'private, no-store, no-cache, must-revalidate'];
        yield 'noStore' => [new CacheableResponseNoStore(), 'no-store'];
    }

    #[DataProvider('unstorableDeclarations')]
    public function testADeclarationWithPrivateOrNoStoreThrows(ResourceObject $ro, string $declared): void
    {
        try {
            $this->declaredCacheControl->storability($ro);
            $this->fail('expected UnstorableDeclaration');
        } catch (UnstorableDeclaration $e) {
            $this->assertSame($declared, $e->cacheControl);
        }
    }

    public function testOrdinaryCacheControlIsShared(): void
    {
        $ro = new None();
        $ro->headers[Header::CACHE_CONTROL] = 'max-age=60';

        $this->assertSame(Storability::Shared, $this->declaredCacheControl->storability($ro));
    }

    public function testEveryCaseVariantOfTheCacheControlKeyIsChecked(): void
    {
        // Two components writing differently-cased keys leave two Cache-Control entries in
        // the plain array; a private one must be refused whichever key comes first.
        $ro = new None();
        $ro->headers = ['Cache-Control' => 'public', 'cache-control' => 'private'];
        $this->assertSame(Storability::Unstorable, $this->declaredCacheControl->storability($ro));

        $reversed = new None();
        $reversed->headers = ['cache-control' => 'private', 'Cache-Control' => 'public'];
        $this->assertSame(Storability::Unstorable, $this->declaredCacheControl->storability($reversed));
    }

    public function testHttpCacheIsTheHttpCacheAttributeOnly(): void
    {
        $this->assertInstanceOf(HttpCache::class, $this->declaredCacheControl->httpCache(new HttpCacheControlWithCacheable()));
        $this->assertNull($this->declaredCacheControl->httpCache(new NoHttpCacheControl()));
        $this->assertNull($this->declaredCacheControl->httpCache(new None()));
    }
}
