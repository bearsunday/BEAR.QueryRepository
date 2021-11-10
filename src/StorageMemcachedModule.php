<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use Psr\Cache\CacheItemPoolInterface;
use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\Annotation\CacheNamespace;
use Ray\PsrCacheModule\Psr6MemcachedModule;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;

final class StorageMemcachedModule extends AbstractModule
{
    /** @var string */
    private $servers;

    /**
     * @param string $servers 'mem1.domain.com:11211:33,mem2.domain.com:11211:67' {host}:{port}:{weight}
     */
    public function __construct(string $servers, ?AbstractModule $module = null)
    {
        $this->servers = $servers;
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->install(new Psr6MemcachedModule($this->servers));
        $this->bind(CacheItemPoolInterface::class)->annotatedWith(EtagPool::class)->toConstructor(MemcachedAdapter::class, [
            'namespace' => CacheNamespace::class,
        ]);
    }
}
