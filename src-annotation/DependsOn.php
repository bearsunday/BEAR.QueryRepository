<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Tags the stored resource with the URI `uri` expands to, so a write to that URI invalidates it and every page that embeds it
 *
 * On a resource's onGet(), every template variable must be an onGet() parameter. On a row class, the variables are its public properties.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class DependsOn
{
    public function __construct(
        public string $uri,
    ) {
    }
}
