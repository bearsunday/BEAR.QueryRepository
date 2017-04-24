<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\Common\Cache\MemcachedCache;
use Ray\Di\Injector;

class StorageMemcachedModuleTest extends \PHPUnit_Framework_TestCase
{
    public function testNew()
    {
        // @see http://php.net/manual/en/memcached.addservers.php
        $servers = [
            ['mem1.domain.com', 11211, 33],
            ['mem2.domain.com', 11211, 67]
        ];
        $cache = (new Injector(new StorageMemcachedModule($servers)))->getInstance(CacheProvider::class, Storage::class);
        $this->assertInstanceOf(MemcachedCache::class, $cache);
    }
}
