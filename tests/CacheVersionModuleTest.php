<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\PsrCacheModule\Annotation\CacheNamespace;

class CacheVersionModuleTest extends TestCase
{
    public function testNew(): void
    {
        $version = '1';
        $module = new CacheVersionModule($version);
        $injector = new Injector($module, $_ENV['TMP_DIR']);
        $ns = $injector->getInstance('', CacheNamespace::class);
        $this->assertSame($version, $ns);
    }
}
