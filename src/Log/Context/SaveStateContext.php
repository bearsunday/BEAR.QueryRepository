<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a resource state was stored in the cache.
 *
 * `kind` (value | view | donut-view) is a lead to which storage path ran, not the full
 * mechanism — the cache type itself is declared by #[Cacheable] in the code.
 */
final class SaveStateContext extends AbstractContext
{
    public const TYPE = 'save_state';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/save_state.json';

    /** @param list<string> $tags */
    public function __construct(
        public readonly string $uri,
        public readonly array $tags,
        public readonly int|null $ttl,
        public readonly string $kind,
    ) {
    }
}
