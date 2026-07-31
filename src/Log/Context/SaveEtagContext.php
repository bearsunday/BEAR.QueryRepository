<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: an ETag entry was stored with its invalidation tags.
 */
final class SaveEtagContext extends AbstractContext
{
    public const TYPE = 'save_etag';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/save_etag.json';

    /** @param list<string> $tags */
    public function __construct(
        public readonly string $uri,
        public readonly string $etag,
        public readonly array $tags,
        public readonly bool $saved,
    ) {
    }
}
