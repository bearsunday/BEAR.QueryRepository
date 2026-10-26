<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreFailure;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;
use RuntimeException;

/**
 * The donut-interceptor twin of FakeReadFailsForOneUri/FakeDonutReadFailsForOneUri, for
 * purge() instead of get(): fails for exactly one URI ($failingUri), either as a degradable
 * `CacheStoreFailure` (a pool outage, the default) or - when $genuine is true - as a plain
 * `RuntimeException` that is not this path's to swallow. Every other call, and every call once
 * $failingUri is null again, delegates to the real, DI-shared DonutRepository.
 */
final class FakePurgeFailsForOneUri implements DonutRepositoryInterface
{
    public string|null $failingUri = null;
    public bool $genuine = false;

    public function __construct(
        private readonly DonutRepository $real,
    ) {
    }

    #[Override]
    public function get(ResourceObject $ro): ResourceObject|null
    {
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
        if ($this->failingUri !== null && (string) $uri === $this->failingUri) {
            if ($this->genuine) {
                throw new RuntimeException('purge genuinely failed for this uri, not a degradable pool outage');
            }

            throw CacheStoreFailure::from(new RuntimeException('purge pool is down for this uri'));
        }

        $this->real->purge($uri);
    }

    /** @param list<string> $tags */
    #[Override]
    public function invalidateTags(array $tags): void
    {
        $this->real->invalidateTags($tags);
    }
}
