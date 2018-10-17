<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheVersion;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\Annotation\AppName;
use Doctrine\Common\Cache\CacheProvider;
use Ray\Di\ProviderInterface;

class StorageProvider implements ProviderInterface
{
    /**
     * @var CacheProvider
     */
    private $cache;

    /**
     * @var string
     */
    private $appName;

    /**
     * @var string
     */
    private $version;

    /**
     * @Storage
     * @AppName("appName")
     * @CacheVersion("version")
     */
    public function __construct(CacheProvider $cache, string $appName, string $version)
    {
        $this->cache = $cache;
        $this->appName = $appName;
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $this->cache->setNamespace($this->appName . $this->version);

        return $this->cache;
    }
}
