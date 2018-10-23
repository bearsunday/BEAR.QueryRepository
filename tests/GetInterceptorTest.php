<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class GetInterceptorTest extends TestCase
{
    /**
     * @var ResourceInterface
     */
    private $resource;

    protected function setUp()
    {
        $this->resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld')), $_ENV['TMP_DIR']))->getInstance(ResourceInterface::class);
        parent::setUp();
    }

    public function testLastModifiedHeader()
    {
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        // put
        $expect = 'Last-Modified';
        $this->assertArrayHasKey($expect, $user->headers);
        $time = $user['time'];
        // get
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $this->assertArrayHasKey($expect, $user->headers);
        $expect = $time;
        $this->assertSame($expect, $user['time']);
    }

    public function testCacheControlHeaderNone()
    {
        $user = $this->resource->get->uri('app://self/control-none')->eager->request();
        $this->assertArrayHasKey('Cache-Control', $user->headers);
        $this->assertSame('max-age=60', $user->headers['Cache-Control']);
    }

    public function testCacheControlHeaderExpiry()
    {
        $user = $this->resource->get->uri('app://self/control-expiry')->eager->request();
        $this->assertArrayHasKey('Cache-Control', $user->headers);
        $this->assertContains('public, max-age=3', $user->headers['Cache-Control']); // 30 sec (but may 30+x sec for slow CI)
    }

    public function testCacheControlHeaderExpiryError()
    {
        $this->expectException(ExpireAtKeyNotExists::class);
        $this->resource->get->uri('app://self/control-expiry-error')->eager->request();
    }

    public function testHttpCacheAnnotation()
    {
        $ro = $this->resource->get->uri('app://self/http-cache-control')->eager->request();
        $this->assertSame($ro->headers['Cache-Control'], 'private, no-cache, no-store, must-revalidate');
    }

    public function testNoHttpCacheAnnotation()
    {
        $ro = $this->resource->get->uri('app://self/no-http-cache-control')->eager->request();
        $this->assertSame($ro->headers['Cache-Control'], 'private, no-store, no-cache, must-revalidate');
    }

    public function testHttpCacheWithCacheble()
    {
        $ro = $this->resource->get->uri('app://self/http-cache-control-with-cacheable')->eager->request();
        $this->assertSame($ro->headers['Cache-Control'], 'private, max-age=10');
    }

    public function testHttpCacheOverrideMaxAge()
    {
        $ro = $this->resource->get->uri('app://self/http-cache-control-override-max-age')->eager->request();
        $this->assertSame($ro->headers['Cache-Control'], 'max-age=5');
    }

    public function testHttpCacheEtag()
    {
        $ro1 = $this->resource->get->uri('app://self/etag')->eager->request();
        $ro2 = $this->resource->get->uri('app://self/etag')->eager->request();
        $ro3 = $this->resource->get->uri('app://self/etag')->withQuery(['updatedAt' => 1])->eager->request();
        $this->assertSame($ro1->headers['ETag'], $ro2->headers['ETag']);
        $this->assertNotSame($ro1->headers['ETag'], $ro3->headers['ETag']);
    }

    public function testHttpCacheVary()
    {
        $ro1 = $this->resource->get->uri('app://self/etag')->eager->request();
        $ro2 = $this->resource->get->uri('app://self/etag')->eager->request();
        $_SERVER['X_VARY'] = 'val1, val2';
        $_SERVER['X_VAL1'] = '1';
        $_SERVER['X_VAL2'] = '2';
        $ro3 = $this->resource->get->uri('app://self/etag')->eager->request();
        $this->assertArrayNotHasKey('Age', $ro1->headers);
        $this->assertArrayHasKey('Age', $ro2->headers);
        $this->assertArrayNotHasKey('Age', $ro3->headers);
    }
}
