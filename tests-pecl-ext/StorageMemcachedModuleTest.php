<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\Shared;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;

class StorageMemcachedModuleTest extends TestCase
{
    public function testNew()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $servers = 'mem1.domain.com:11211:33,mem2.domain.com:11211:67';
        $cache = (new Injector(new StorageMemcachedModule($servers)))->getInstance(CacheItemPoolInterface::class, Shared::class);
        $this->assertInstanceOf(MemcachedAdapter::class, $cache);
    }
}
