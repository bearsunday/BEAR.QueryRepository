<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a donut was put with its TTLs.
 */
final class PutDonutContext extends AbstractContext
{
    public const TYPE = 'put_donut';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/put_donut.json';

    public function __construct(
        public readonly string $uri,
        public readonly int|null $ttl,
        public readonly int|null $sMaxAge,
    ) {
    }
}
