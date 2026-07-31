<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a donut structure (template) was stored.
 */
final class SaveDonutContext extends AbstractContext
{
    public const TYPE = 'save_donut';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/save_donut.json';

    public function __construct(
        public readonly string $uri,
        public readonly int|null $ttl,
        public readonly bool $saved,
    ) {
    }
}
