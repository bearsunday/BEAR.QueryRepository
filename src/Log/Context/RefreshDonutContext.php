<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a donut was rebuilt (cache miss path).
 */
final class RefreshDonutContext extends AbstractContext
{
    public const TYPE = 'refresh_donut';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/refresh_donut.json';

    public function __construct(
        public readonly string $uri,
    ) {
    }
}
