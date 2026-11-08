<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class CacheableTransferInterceptorTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $this->resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    private function transferredCacheControl(ResourceObject $ro): string|null
    {
        $responder = new FakeRecordingResponder();
        $ro->transfer($responder, []);

        return $responder->headers[Header::CACHE_CONTROL] ?? null;
    }

    public function testAnEntryWithoutALifetimeTellsTheClientToRevalidate(): void
    {
        $user = $this->resource->get('app://self/user', ['id' => 1]);

        $this->assertSame('no-cache', $this->transferredCacheControl($user));
    }

    public function testAHitTransfersTheCacheControlAMissDid(): void
    {
        $miss = $this->resource->get('app://self/control-none');
        $this->assertArrayNotHasKey(Header::CACHE_CONTROL, $miss->headers, 'storing the entry sets no client directive');
        $missCacheControl = $this->transferredCacheControl($miss);

        $hit = $this->resource->get('app://self/control-none');
        $this->assertArrayHasKey(Header::AGE, $hit->headers, 'the second read is a hit');

        $this->assertSame('max-age=60', $missCacheControl);
        $this->assertSame($missCacheControl, $this->transferredCacheControl($hit));
    }

    public function testTheResourceOwnPrivateCacheControlStands(): void
    {
        $ro = $this->resource->get('app://self/hand-set-private-cache-control');

        $this->assertSame('private', $this->transferredCacheControl($ro));
    }

    public function testADeclaredLifetimeStands(): void
    {
        $ro = $this->resource->get('app://self/http-cache-control-override-max-age');

        $this->assertSame('max-age=5', $this->transferredCacheControl($ro));
    }
}
