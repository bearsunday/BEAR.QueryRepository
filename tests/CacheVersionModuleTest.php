<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\Module\ResourceModule;
use Doctrine\Common\Cache\Cache;
use Ray\Di\Injector;

class CacheVersionModuleTest extends \PHPUnit_Framework_TestCase
{
    public function testNew()
    {
        $namespace = 'FakeVendor\HelloWorld';
        $version = '1';
        $injector = new Injector(new CacheVersionModule($version, new QueryRepositoryModule(new MobileEtagModule(new ResourceModule($namespace))), $_ENV['TMP_DIR']));
        $cache = $injector->getInstance(Cache::class, Storage::class);
        /* @var $cache \Doctrine\Common\Cache\CacheProvider */
        $ns = $cache->getNamespace();
        $expected = $namespace . $version;
        $this->assertSame($expected, $ns);
    }
}
