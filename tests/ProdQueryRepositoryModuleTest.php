<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;

class ProdQueryRepositoryModuleTest extends TestCase
{
    public function testBindsTheResourceObjectPoolToALocalCache(): void
    {
        $pool = (new Injector(new ProdQueryRepositoryModule()))->getInstance(AdapterInterface::class, ResourceObjectPool::class);
        $this->assertInstanceOf(AdapterInterface::class, $pool);
    }
}
