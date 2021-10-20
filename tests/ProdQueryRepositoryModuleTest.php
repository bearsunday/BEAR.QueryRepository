<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\KnownTagTtl;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class ProdQueryRepositoryModuleTest extends TestCase
{
    public function testBind(): void
    {
        $ttl = (new Injector(new ProdQueryRepositoryModule()))->getInstance('', KnownTagTtl::class);
        $this->assertSame(0.15, $ttl);
    }
}
