<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\Resource\ResourceInterface;
use FakeVendor\HelloWorld\Resource\App\HttpCacheControlWithCacheable;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function preg_match;
use function sleep;
use function strtotime;
use function time;

class GetInterceptorTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $this->resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testLastModifiedHeader(): void
    {
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        // put
        $expect = 'Last-Modified';
        $this->assertArrayHasKey($expect, $user->headers);
        $time = $user['time'];
        // get
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $this->assertArrayHasKey($expect, $user->headers);
        $expect = $time;
        $this->assertSame($expect, $user['time']);
    }

    public function testCacheControlHeaderNone(): void
    {
        $user = $this->resource->get('app://self/control-none');
        $this->assertArrayHasKey('Cache-Control', $user->headers);
        $this->assertSame('max-age=60', $user->headers['Cache-Control']);
    }

    public function testCacheControlHeaderExpiry(): void
    {
        $user = $this->resource->get('app://self/control-expiry');
        $this->assertArrayHasKey('Cache-Control', $user->headers);
        $this->assertStringContainsString('public, max-age=3', $user->headers['Cache-Control']); // 30 sec (but may 30+x sec for slow CI)
    }

    public function testAnEntryWithoutALifetimeTellsTheClientToRevalidate(): void
    {
        $first = $this->resource->get('app://self/user', ['id' => 1]);
        $hit = $this->resource->get('app://self/user', ['id' => 1]);

        $this->assertSame('no-cache', $first->headers['Cache-Control']);
        $this->assertArrayHasKey('Age', $hit->headers, 'the second read is a hit');
        $this->assertSame('no-cache', $hit->headers['Cache-Control']);
    }

    public function testAnExpiryAtHitLeavesTheClientTheRemainingLifetime(): void
    {
        $uris = ['app://self/control-expiry', 'app://self/control-expiry-view'];
        foreach ($uris as $uri) {
            $this->resource->get($uri);
        }

        sleep(2);
        foreach ($uris as $uri) {
            $before = time();
            $hit = $this->resource->get($uri);
            $after = time();
            $age = (int) $hit->headers['Age'];
            $this->assertGreaterThanOrEqual(2, $age, $uri . ' is a hit stored at least two seconds ago');
            if (preg_match('/max-age=(\d+)/', $hit->headers['Cache-Control'], $maxAge) !== 1) {
                $this->fail($uri . ' carries a max-age');
            }

            $this->assertIsArray($hit->body);
            $expiryAt = $hit->body['expiry_at'];
            $this->assertIsString($expiryAt);
            $expiresAt = (int) strtotime($expiryAt);
            // A client counts the stored max-age down from Age: what is left is the time to expiry
            // when the hit was served, plus at most the second the store took to record its time
            $left = (int) $maxAge[1] - $age;
            $this->assertGreaterThanOrEqual($expiresAt - $after, $left, $uri);
            $this->assertLessThanOrEqual($expiresAt - $before + 1, $left, $uri);
        }
    }

    public function testCacheControlHeaderExpiryError(): void
    {
        $this->expectException(ExpireAtKeyNotExists::class);
        $this->resource->get('app://self/control-expiry-error');
    }

    public function testHttpCacheAnnotation(): void
    {
        $ro = $this->resource->get('app://self/http-cache-control');
        $this->assertSame('private, no-cache, no-store, must-revalidate', $ro->headers['Cache-Control']);
    }

    public function testNoHttpCacheAnnotation(): void
    {
        $ro = $this->resource->get('app://self/no-http-cache-control');
        $this->assertSame('private, no-store, no-cache, must-revalidate', $ro->headers['Cache-Control']);
    }

    public function testHttpCacheWithCacheble(): void
    {
        HttpCacheControlWithCacheable::$calls = 0;
        $ro = $this->resource->get('app://self/http-cache-control-with-cacheable');
        $this->assertSame('private, max-age=10', $ro->headers['Cache-Control']);

        // #[HttpCache(isPrivate: true)]'s own declared value is exempt from the runtime-private
        // check: a second GET is served from the server-side store, not a fresh onGet() call.
        $this->resource->get('app://self/http-cache-control-with-cacheable');
        $this->assertSame(1, HttpCacheControlWithCacheable::$calls, 'the server-side store must still hold this declared-private value');
    }

    public function testHttpCacheOverrideMaxAge(): void
    {
        $ro = $this->resource->get('app://self/http-cache-control-override-max-age');
        $this->assertSame('max-age=5', $ro->headers['Cache-Control']);
    }

    public function testHttpCacheEtag(): void
    {
        $ro1 = $this->resource->get('app://self/etag');
        $ro2 = $this->resource->get('app://self/etag');
        $ro3 = $this->resource->get('app://self/etag', ['updatedAt' => 1]);
        $this->assertSame($ro1->headers[Header::ETAG], $ro2->headers[Header::ETAG]);
        $this->assertNotSame($ro1->headers[Header::ETAG], $ro3->headers[Header::ETAG]);
    }

    public function testHttpCacheVary(): void
    {
        $ro1 = $this->resource->get('app://self/etag');
        $ro2 = $this->resource->get('app://self/etag');
        $_SERVER['X_VARY'] = 'val1, val2';
        $_SERVER['X_VAL1'] = '1';
        $_SERVER['X_VAL2'] = '2';
        $ro3 = $this->resource->get('app://self/etag');
        $this->assertArrayNotHasKey('Age', $ro1->headers);
        $this->assertArrayHasKey('Age', $ro2->headers);
        $this->assertArrayNotHasKey('Age', $ro3->headers);

        unset($_SERVER['X_VARY'], $_SERVER['X_VAL1'], $_SERVER['X_VAL2']);
    }

    public function testHttpCacheVaryWithEmptySegment(): void
    {
        $_SERVER['X_VARY'] = 'val1, , val2';
        $_SERVER['X_VAL1'] = '1';
        $_SERVER['X_VAL2'] = '2';
        $ro = $this->resource->get('app://self/etag');
        $this->assertArrayNotHasKey('Age', $ro->headers);

        unset($_SERVER['X_VARY'], $_SERVER['X_VAL1'], $_SERVER['X_VAL2']);
    }
}
