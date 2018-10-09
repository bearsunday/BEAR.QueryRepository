<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use Doctrine\Common\Cache\ApcuCache;
use Doctrine\Common\Cache\CacheProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class StorageApcModuleTest extends TestCase
{
    public function testNew()
    {
        $cache = (new Injector(new StorageApcModule, $_ENV['TMP_DIR']))->getInstance(CacheProvider::class, Storage::class);
        $this->assertInstanceOf(ApcuCache::class, $cache);
    }
}
