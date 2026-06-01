<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Open: a resource (or donut) GET scope; embedded child GETs nest under it.
 */
final class GetContext extends AbstractContext
{
    public const TYPE = 'get';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/get.json';

    public function __construct(
        public readonly string $uri,
    ) {
    }
}
