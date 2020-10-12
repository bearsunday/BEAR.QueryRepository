<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\MemcachedCache;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class StorageMemcachedModuleTest extends TestCase
{
    public function testNew()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $servers = 'mem1.domain.com:11211:33,mem2.domain.com:11211:67';
        $cache = (new Injector(new StorageMemcachedModule($servers)))->getInstance(CacheProvider::class, Storage::class);
        $this->assertInstanceOf(MemcachedCache::class, $cache);
    }
}
