<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a parent now depends on a child (dependency-graph edge).
 */
final class DependsOnContext extends AbstractContext
{
    public const TYPE = 'depends_on';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/depends_on.json';

    /** @param list<string> $childTags */
    public function __construct(
        public readonly string $parent,
        public readonly string $child,
        public readonly array $childTags,
    ) {
    }
}
