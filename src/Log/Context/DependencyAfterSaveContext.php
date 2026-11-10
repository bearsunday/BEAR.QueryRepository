<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: a #[Cacheable] value resource declared a dependency outside its own put
 *
 * Usually a template helper's depends() while the page renders. The child's tags reach the response
 * header only, so a change to the child does not invalidate the stored entry or its ETag.
 */
final class DependencyAfterSaveContext extends AbstractContext
{
    public const TYPE = 'dependency_after_save';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/dependency_after_save.json';

    public function __construct(
        public readonly string $parent,
        public readonly string $child,
    ) {
    }
}
