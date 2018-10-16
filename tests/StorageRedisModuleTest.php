<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\RedisCache;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class StorageRedisModuleTest extends TestCase
{
    public function testNew()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $server = 'localhost:6379';
        $cache = (new Injector(new StorageRedisModule($server), $_ENV['TMP_DIR']))->getInstance(CacheProvider::class, Storage::class);
        $this->assertInstanceOf(RedisCache::class, $cache);
    }
}
