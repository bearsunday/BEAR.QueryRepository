<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreFailure;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Override;
use RuntimeException;

/**
 * A QueryRepositoryInterface decorator whose read degrades - CacheStoreFailure, triggering
 * CacheInterceptor's warning+proceed() fallback, never a genuine read defect - for exactly one
 * URI ($failingUri, set after construction, before the request that targets it). Every other
 * URI - in particular a sibling read triggered from inside the target's own onGet() - still
 * reads through the real, DI-shared QueryRepository (same UncacheableMark/pools as the rest of
 * the container), so the write-skip predicate under test is unaffected by this decorator.
 */
final class FakeReadFailsForOneUri implements QueryRepositoryInterface
{
    public string|null $failingUri = null;

    public function __construct(
        private readonly QueryRepository $real,
    ) {
    }

    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        if ($this->failingUri !== null && (string) $uri === $this->failingUri) {
            throw CacheStoreFailure::from(new RuntimeException('store read is down for this uri'));
        }

        return $this->real->get($uri);
    }

    #[Override]
    public function put(ResourceObject $ro)
    {
        return $this->real->put($ro);
    }

    #[Override]
    public function purge(AbstractUri $uri)
    {
        return $this->real->purge($uri);
    }
}
