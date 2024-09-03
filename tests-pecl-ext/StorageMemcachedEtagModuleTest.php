<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\Shared;
use Ray\PsrCacheModule\MemcachedAdapter;

class StorageMemcachedEtagModuleTest extends TestCase
{
    public function testNew(): void
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $servers = 'mem1.domain.com:11211:33,mem2.domain.com:11211:67';
        $cache = (new Injector(new StorageMemcachedEtagModule($servers)))->getInstance(CacheItemPoolInterface::class, EtagPool::class);
        $this->assertInstanceOf(MemcachedAdapter::class, $cache);
    }
}
