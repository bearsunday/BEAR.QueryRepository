<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\Psr6MemcachedModule;

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
    protected function configure()
    {
        $this->install(new Psr6MemcachedModule($this->servers));
    }
}
