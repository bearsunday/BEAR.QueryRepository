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
     * @Named("namespace=cache_namespace")
     */
    public function __construct(string $namespace = '')
    {
        $this->namespace = $namespace;
    }

    /**
     * {@inheritdoc}
     */
    public function get()
    {
        $cache = new ApcuCache;
        $cache->setNamespace($this->namespace);

        return $cache;
    }
}
