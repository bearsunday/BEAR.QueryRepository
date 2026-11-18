<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Tags the stored resource with each URI `uri` expands to, so a write to that URI invalidates it and every page that embeds it
 *
 * On a resource's onGet(), `key` names the rows in the body onGet() returns, and without `key` the body itself holds them: a list
 * (an array list or a Traversable) names one URI per row, one array or object one URI; an object row is read as `(array)`. A
 * variable the row lacks is read from the body's top level, as #[Link] expands its href, then from the onGet() arguments. A list given to an
 * exploded query variable (`{?id*}`, `?id={ids*}`) names one URI per element, as #[Purge] does. On a row class, the variables are
 * its public properties; deprecated, put the rows in the body under `key` instead.
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#dependson
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class DependsOn
{
    public function __construct(
        public string $uri,
        public string|null $key = null,
    ) {
    }
}
