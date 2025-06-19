<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\RedisDsn;
use BEAR\RepositoryModule\Annotation\RedisDsnOptions;
use Override;
use Predis\ClientInterface;
use Ray\Di\ProviderInterface;
use Redis;
use RedisArray;
use RedisCluster;
use Relay\Cluster as RelayCluster;
use Relay\Relay;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/** @implements ProviderInterface<Redis|RedisArray|RedisCluster|ClientInterface|Relay|RelayCluster> */
final class RedisDsnProvider implements ProviderInterface
{
    /**
     * @param string                                                   $dns     Redis DSN
     * @param array<string, bool|int|string|array<string, mixed>|null> $options Redis DSN Options
     */
    public function __construct(
        #[RedisDsn]
        private string $dns,
        #[RedisDsnOptions]
        private array $options,
    ) {
    }

    #[Override]
    public function get(): Redis|RedisArray|RedisCluster|ClientInterface|Relay|RelayCluster
    {
        /** @var Redis|RedisArray|RedisCluster|ClientInterface|Relay|RelayCluster $connection */
        $connection = RedisAdapter::createConnection($this->dns, $this->options);

        return $connection;
    }
}
