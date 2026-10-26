<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;
use RuntimeException;

/**
 * A ResourceStorageInterface decorator whose deleteEtag() throws a genuine (non-degradable)
 * RuntimeException - never CacheStoreFailure - for exactly one URI ($failingUri, set after
 * construction). Every other call delegates to the real, DI-shared storage, so a request that
 * never targets $failingUri is unaffected by this decorator.
 */
final class FakeDeleteEtagFailsForOneUri implements ResourceStorageInterface
{
    public string|null $failingUri = null;

    public function __construct(
        private readonly ResourceStorageInterface $storage,
    ) {
    }

    #[Override]
    public function hasEtag(string $etag): bool
    {
        return $this->storage->hasEtag($etag);
    }

    #[Override]
    public function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, int|null $ttl): void
    {
        $this->storage->saveEtag($uri, $etag, $surrogateKeys, $ttl);
    }

    /** @inheritDoc */
    #[Override]
    public function deleteEtag(AbstractUri $uri)
    {
        if ($this->failingUri !== null && (string) $uri === $this->failingUri) {
            throw new RuntimeException('deleteEtag genuinely failed for this uri, not a degradable store outage');
        }

        return $this->storage->deleteEtag($uri);
    }

    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        return $this->storage->get($uri);
    }

    /** @inheritDoc */
    #[Override]
    public function saveValue(ResourceObject $ro, int $ttl)
    {
        return $this->storage->saveValue($ro, $ttl);
    }

    /** @inheritDoc */
    #[Override]
    public function saveView(ResourceObject $ro, int $ttl)
    {
        return $this->storage->saveView($ro, $ttl);
    }

    #[Override]
    public function getDonut(AbstractUri $uri): ResourceDonut|null
    {
        return $this->storage->getDonut($uri);
    }

    /** @inheritDoc */
    #[Override]
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
        $this->storage->saveDonut($uri, $donut, $sMaxAge, $headerKeys);
    }

    #[Override]
    public function saveDonutView(ResourceObject $ro, int|null $ttl, array|null $tags = null): bool
    {
        return $this->storage->saveDonutView($ro, $ttl, $tags);
    }

    /** @inheritDoc */
    #[Override]
    public function invalidateTags(array $tags): bool
    {
        return $this->storage->invalidateTags($tags);
    }
}
