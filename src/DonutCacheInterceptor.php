<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Interceptor for donut caching on CQRS queries with #[DonutCache]
 *
 * Bound to query methods (onGet) or individual methods marked with #[DonutCache].
 * Caches only the cacheable portions, excluding non-cacheable embedded content.
 * No ETag is generated for the entire response.
 *
 * @see \BEAR\RepositoryModule\Annotation\DonutCache
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#donut-cache
 */
final class DonutCacheInterceptor extends AbstractDonutCacheInterceptor
{
    protected const IS_ENTIRE_CONTENT_CACHEABLE = false;
}
