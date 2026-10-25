<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreFailure;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;
use RuntimeException;

/**
 * The donut-interceptor twin of FakeReadFailsForOneUri: degrades DonutRepositoryInterface::get()
 * - CacheStoreFailure, triggering AbstractDonutCacheInterceptor's warning+proceed() fallback -
 * for exactly one URI, every other call delegating to the real, DI-shared DonutRepository.
 */
final class FakeDonutReadFailsForOneUri implements DonutRepositoryInterface
{
    public string|null $failingUri = null;

    public function __construct(
        private readonly DonutRepository $real,
    ) {
    }

    #[Override]
    public function get(ResourceObject $ro): ResourceObject|null
    {
        if ($this->failingUri !== null && (string) $ro->uri === $this->failingUri) {
            throw CacheStoreFailure::from(new RuntimeException('store read is down for this uri'));
        }

        return $this->real->get($ro);
    }

    #[Override]
    public function putStatic(ResourceObject $ro, int|null $ttl = null, int|null $sMaxAge = null): ResourceObject
    {
        return $this->real->putStatic($ro, $ttl, $sMaxAge);
    }

    #[Override]
    public function putDonut(ResourceObject $ro, int|null $donutTtl): ResourceObject
    {
        return $this->real->putDonut($ro, $donutTtl);
    }

    #[Override]
    public function purge(AbstractUri $uri): void
    {
        $this->real->purge($uri);
    }

    /** @param list<string> $tags */
    #[Override]
    public function invalidateTags(array $tags): void
    {
        $this->real->invalidateTags($tags);
    }
}
