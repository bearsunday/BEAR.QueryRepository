<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Builds the coarser surrogate tag that write-side purges (#[Purge]/#[Refresh]'s URI, and
 * MatchQuery-based auto-purge) emit for a GET resource, so a query-variant cache entry carries a
 * tag that those purges can reach in addition to its own full-query tag.
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/255
 */
interface BaseUriTagInterface
{
    /** Null when $ro has no onGet() (not cacheable) */
    public function __invoke(ResourceObject $ro): string|null;
}
