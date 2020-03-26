<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\ApcuCache;
use Ray\Di\Di\Named;
use Ray\Di\ProviderInterface;

class StorageApcCacheProvider implements ProviderInterface
{
    /**
     * @var string
     */
    private $namespace;

    /**
     * @var string
     */
    private $appName;

    /**
     * @var string
     */
    private $version;

    /**
     * @Named("namespace=cache_namespace")
     */
    public function __construct(string $namespace = '', string $appName = '', string $version = '')
    {
        $this->namespace = $namespace;
        $this->appName = $appName;
        $this->version = $version;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $cache = new ApcuCache;
        $cache->setNamespace($this->namespace . $this->appName . $this->version);

        return $cache;
    }
}
