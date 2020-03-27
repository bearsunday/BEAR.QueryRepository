<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheEngine;
use BEAR\RepositoryModule\Annotation\CacheVersion;
use BEAR\Resource\Annotation\AppName;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\ProviderInterface;

class NamespacedCacheProvider implements ProviderInterface
{
    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @CacheEngine("cache")
     * @AppName("appName")
     * @CacheVersion("version")
     */
    public function __construct(CacheProvider $cache, string $appName = '', string $version = '')
    {
        $cache->setNamespace($appName . ':' . $version);
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        return $this->cache;
    }
}
