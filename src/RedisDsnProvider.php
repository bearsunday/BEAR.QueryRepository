<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\RedisDsn;
use BEAR\RepositoryModule\Annotation\RedisDsnOptions;
use Predis\ClientInterface;
use Ray\Di\ProviderInterface;
use Redis;
use RedisArray;
use RedisCluster;
use Relay\Relay;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * @implements ProviderInterface<Redis|RedisArray|RedisCluster|ClientInterface|Relay>
 */
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

    public function get(): Redis|RedisArray|RedisCluster|ClientInterface|Relay
    {
        return RedisAdapter::createConnection($this->dns, $this->options);
    }
}
