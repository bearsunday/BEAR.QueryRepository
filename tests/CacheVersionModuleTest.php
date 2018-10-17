<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\Module\ResourceModule;
use Doctrine\Common\Cache\Cache;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\NullModule;

class CacheVersionModuleTest extends TestCase
{
    public function testNew()
    {
        $namespace = 'FakeVendor\HelloWorld';
        $version = '1';
        $module = new NullModule;
        $module->install(new CacheVersionModule($version));
        $module->install(new QueryRepositoryModule(new ResourceModule($namespace)));
        $injector = new Injector($module, $_ENV['TMP_DIR']);
        $cache = $injector->getInstance(Cache::class, Storage::class);
        /* @var $cache \Doctrine\Common\Cache\CacheProvider */
        $ns = $cache->getNamespace();
        $expected = $namespace . $version;
        $this->assertSame($expected, $ns);
    }
}
