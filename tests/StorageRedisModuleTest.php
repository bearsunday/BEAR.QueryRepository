<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\RedisCache;
use Ray\Di\Injector;

class StorageRedisModuleTest extends \PHPUnit_Framework_TestCase
{
    public function testNew()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $server = 'localhost:6379';
        $cache = (new Injector(new StorageRedisModule($server)))->getInstance(CacheProvider::class, Storage::class);
        $this->assertInstanceOf(RedisCache::class, $cache);
    }
}
