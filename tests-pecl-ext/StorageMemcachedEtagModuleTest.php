<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheException;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Injector;
use Ray\PsrCacheModule\MemcachedAdapter;

use function assert;
use function extension_loaded;
use function phpversion;
use function version_compare;

class StorageMemcachedEtagModuleTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('memcached') || version_compare(phpversion('memcached'), '2.2.0', '<')) {
            $this->markTestSkipped('Memcached extension >= 2.2.0 is required');
        }
    }

    public function testNew(): void
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $servers = 'mem1.domain.com:11211:33,mem2.domain.com:11211:67';
        $cache = (new Injector(new StorageMemcachedEtagModule($servers)))->getInstance(CacheItemPoolInterface::class, EtagPool::class);
        $this->assertInstanceOf(MemcachedAdapter::class, $cache);
    }

    public function testInvalidServerString(): void
    {
        $this->expectException(CacheException::class);
        $servers = 'invalid:server:string';
        $cache = (new Injector(new StorageMemcachedEtagModule($servers)))
            ->getInstance(CacheItemPoolInterface::class, EtagPool::class);
        assert($cache instanceof CacheItemPoolInterface);
        $cache->clear();
    }
}
