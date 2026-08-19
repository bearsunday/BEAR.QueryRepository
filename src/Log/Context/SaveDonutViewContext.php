<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a rendered donut view was stored.
 */
final class SaveDonutViewContext extends AbstractContext
{
    public const TYPE = 'save_donut_view';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/save_donut_view.json';

    /** @param list<string> $tags */
    public function __construct(
        public readonly string $uri,
        public readonly array $tags,
        public readonly int|null $requestedTtl,
        public readonly bool $saved,
    ) {
    }
}
