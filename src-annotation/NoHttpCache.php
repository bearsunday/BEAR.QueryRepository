<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\HttpCacheInterceptor;

/**
 * HTTP Cache Control
 *
 * Simplified notation to say that you don't want anything cached
 *
 * Interceptors bound:
 * - HttpCacheInterceptor (onGet methods)
 *
 * @see HttpCacheInterceptor
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NoHttpCache extends AbstractCacheControl
{
    public function __toString(): string
    {
        return 'private, no-store, no-cache, must-revalidate';
    }
}
