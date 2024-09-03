<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\RedisDsn;
use Predis\ClientInterface;
use Ray\Di\ProviderInterface;
use Redis;
use RedisArray;
use RedisCluster;
use Relay\Relay;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/** @implements ProviderInterface<Redis|RedisArray|RedisCluster|ClientInterface|Relay> */
final class RedisDsnProvider implements ProviderInterface
{
    /** @param string $redisDsn Redis */
    public function __construct(
        #[RedisDsn]
        private string $redisDsn,
    ) {
    }

    public function get(): Redis|RedisArray|RedisCluster|ClientInterface|Relay
    {
        return RedisAdapter::createConnection($this->redisDsn);
    }
}
