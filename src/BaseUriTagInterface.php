<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * The group tag of a GET resource: its URI tag without the default-valued onGet() parameters.
 * A query-variant cache entry carries it besides its own tag, and a purge of the bare URI targets it.
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/255
 */
interface BaseUriTagInterface
{
    /** Without onGet(), the group tag is derived from the full URI. */
    public function __invoke(ResourceObject $ro): string;
}
