<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Sets `Cache-Control: private, no-store, no-cache, must-revalidate` on an onGet() response with status 200
 *
 * A resource stored by #[Cacheable], #[CacheableResponse] or #[DonutCache] throws UnstorableDeclaration.
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
