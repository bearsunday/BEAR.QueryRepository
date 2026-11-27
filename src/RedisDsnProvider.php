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

/**
 * @implements ProviderInterface<Redis|RedisArray|RedisCluster|ClientInterface|Relay|RelayCluster>
 * @internal
 */
final readonly class RedisDsnProvider implements ProviderInterface
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
        // Connect on first use: the pool is built at boot, where a compile has no store to reach.
        // A `lazy` in the options or the DSN query still wins.
        /** @var Redis|RedisArray|RedisCluster|ClientInterface|Relay|RelayCluster $connection */
        $connection = RedisAdapter::createConnection($this->dns, $this->options + ['lazy' => true]);

        return $connection;
    }
}
