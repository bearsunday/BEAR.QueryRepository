<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class AttributeTest extends TestCase
{
    public function testReadAttributes() : void
    {
        $class = new ReflectionClass(FakeAttributes::class);

        $cacheableAttrs = $class->getAttributes(Cacheable::class);
        $this->assertNotEmpty($cacheableAttrs);
        $cacheable = $cacheableAttrs[0]->newInstance();
        $this->assertInstanceOf(Cacheable::class, $cacheable);

        $noHttpCacheAttrs = $class->getAttributes(NoHttpCache::class);
        $this->assertNotEmpty($noHttpCacheAttrs);
        $noHttpCache = $noHttpCacheAttrs[0]->newInstance();
        $this->assertInstanceOf(NoHttpCache::class, $noHttpCache);

        $method = new ReflectionMethod(FakeAttributes::class, 'onGet');

        $purgeAttrs = $method->getAttributes(Purge::class);
        $this->assertNotEmpty($purgeAttrs);
        $purge = $purgeAttrs[0]->newInstance();
        $this->assertInstanceOf(Purge::class, $purge);

        $refreshAttrs = $method->getAttributes(Refresh::class);
        $this->assertNotEmpty($refreshAttrs);
        $refresh = $refreshAttrs[0]->newInstance();
        $this->assertInstanceOf(Refresh::class, $refresh);
    }
}
