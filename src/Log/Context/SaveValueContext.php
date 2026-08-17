<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a resource value (body) was stored.
 */
final class SaveValueContext extends AbstractContext
{
    public const TYPE = 'save_value';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/save_value.json';

    /** @param list<string> $tags */
    public function __construct(
        public readonly string $uri,
        public readonly array $tags,
        public readonly int|null $requestedTtl,
        public readonly bool $saved,
    ) {
    }
}
