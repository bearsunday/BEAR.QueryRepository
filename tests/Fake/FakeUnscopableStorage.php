<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * A storage that implements the released contract and nothing else
 *
 * What an application's own `ResourceStorageInterface` looked like before validators knew which
 * resource issued them. The scoped answer has to fall back to the older question for it, or
 * upgrading this package would answer 200 to every revalidation such an application ever gets.
 */
final class FakeUnscopableStorage implements ResourceStorageInterface
{
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
    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool
    {
        return $this->storage->saveDonutView($ro, $ttl);
    }

    /** @inheritDoc */
    #[Override]
    public function invalidateTags(array $tags): bool
    {
        return $this->storage->invalidateTags($tags);
    }
}
