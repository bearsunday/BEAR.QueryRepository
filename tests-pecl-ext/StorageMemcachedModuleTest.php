<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\Shared;
use Ray\PsrCacheModule\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

class StorageMemcachedModuleTest extends TestCase
{
    public function testNew(): void
    {
        if (!extension_loaded('memcached') || version_compare(phpversion('memcached'), '2.2.0', '<')) {
            $this->markTestSkipped('Memcached extension >= 2.2.0 is required');
        }

        // @see http://php.net/manual/en/memcached.addservers.php
        $servers = 'mem1.domain.com:11211:33,mem2.domain.com:11211:67';
        $module = new QueryRepositoryModule();
        $module->override(new StorageMemcachedModule($servers));
        $injector = (new Injector($module, __DIR__ . '/tmp'));
        $storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->assertInstanceOf(ResourceStorage::class, $storage);
        $adapter = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(TagAwareAdapter::class, $adapter);
        $roPool = $injector->getInstance(\Symfony\Component\Cache\Adapter\AdapterInterface::class, ResourceObjectPool::class);
        $etagPool = $injector->getInstance(\Symfony\Component\Cache\Adapter\AdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(MemcachedAdapter::class, $roPool);
        $this->assertInstanceOf(MemcachedAdapter::class, $etagPool);
    }
}
