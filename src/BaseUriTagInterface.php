<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Builds the coarser surrogate tag that write-side purges (#[Purge]/#[Refresh]'s URI, and
 * MatchQuery-based auto-purge) emit for a GET resource, so a query-variant cache entry carries a
 * tag that those purges can reach in addition to its own full-query tag.
 *
 * @internal not a supported customization point - which query params a purge coarsens away is a
 * correctness detail that must agree with #[Purge]/MatchQuery, not a tunable policy. Customize
 * tag format/namespace via UriTagInterface instead.
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/255
 */
interface BaseUriTagInterface
{
    /** The GET's own URI tag when $ro has no onGet() (not cacheable) */
    public function __invoke(ResourceObject $ro): string;
}
