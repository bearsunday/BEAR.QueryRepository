<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

class NamespacedCacheProviderTest extends TestCase
{
    public function testNew()
    {
        $provider = new NamespacedCacheProvider(new ArrayCache, 'app', '1');
        $cache = $provider->get();
        $this->assertSame('app:1', $cache->getNamespace());
    }
}
