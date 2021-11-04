<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;
use Ray\PsrCacheModule\Psr6RedisModule;

final class StorageRedisModule extends AbstractModule
{
    /** @var string */
    private $server;

    /**
     * @param string $server 'localhost:6379' {host}:{port}
     */
    public function __construct(string $server, ?AbstractModule $module = null)
    {
        $this->server = $server;
        parent::__construct($module);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->install(new Psr6RedisModule($this->server));
    }
}
