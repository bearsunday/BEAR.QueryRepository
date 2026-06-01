<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a rendered resource view was stored.
 */
final class SaveViewContext extends AbstractContext
{
    public const TYPE = 'save_view';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/save_view.json';

    public function __construct(
        public readonly string $uri,
        public readonly int|null $ttl,
    ) {
    }
}
