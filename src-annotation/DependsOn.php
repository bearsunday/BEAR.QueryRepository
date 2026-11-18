<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Tags the stored resource with each URI `uri` expands to, so a write to that URI invalidates it and every page that embeds it
 *
 * On a resource's onGet(), as #[Link] expands its href, each variable is a top-level key of the body onGet() returns, or else an
 * onGet() argument. A list given to an exploded query variable (`{?id*}`, `?id={ids*}`) names one URI per element, as #[Purge] does.
 * On a row class, the variables are its public properties; deprecated, put the values in the body instead.
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#dependson
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class DependsOn
{
    public function __construct(
        public string $uri,
    ) {
    }
}
