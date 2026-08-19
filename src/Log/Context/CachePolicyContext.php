<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: what the resource declared about its lifetime.
 *
 * The resolved TTL alone cannot say whether an entry is meant to expire: the `never` preset
 * resolves to a finite number, and which number that is depends on how the application bound
 * `Expiry`. The declaration is deployment-independent, so it is recorded as declared.
 */
final class CachePolicyContext extends AbstractContext
{
    public const TYPE = 'cache_policy';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/cache_policy.json';

    /**
     * Exactly one of the three declarations is non-null: the one that decided this entry.
     *
     * @param 'short'|'medium'|'long'|'never'|null $expiry
     */
    public function __construct(
        public readonly string $uri,
        public readonly string|null $expiry,
        public readonly int|null $expirySecond,
        public readonly string|null $expiryAt,
        public readonly int $resolvedTtl,
    ) {
    }
}
