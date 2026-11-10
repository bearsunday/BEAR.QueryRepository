<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Tags the resource with the URI each body row expands `uri` to, so a write to that URI invalidates it
 *
 * The body must be a list of rows; a page depends on the rows by embedding this resource with #[Embed].
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class DependsOn
{
    public function __construct(
        public string $uri,
    ) {
    }
}
