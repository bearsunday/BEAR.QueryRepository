<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Says that nothing is cached: sets `Cache-Control: private, no-store, no-cache, must-revalidate` on the onGet() response
 *
 * @see \BEAR\QueryRepository\HttpCacheInterceptor
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NoHttpCache extends AbstractCacheControl
{
    public function __toString(): string
    {
        return 'private, no-store, no-cache, must-revalidate';
    }
}
