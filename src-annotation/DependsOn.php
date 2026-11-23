<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Tags the stored resource with each URI `uri` expands to, so a write to that URI invalidates it and every page that embeds it
 *
 * On onGet(), `key` names the rows in the body it returns, or the body itself without `key`, and each row names one URI; a variable
 * no row holds is read from the body's top level, then the onGet() arguments.
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#dependson
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class DependsOn
{
    public function __construct(
        public string $uri,
        public string|null $key = null,
    ) {
    }
}
