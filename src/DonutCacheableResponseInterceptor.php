<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Interceptor for full response caching on CQRS queries with #[CacheableResponse]
 *
 * Bound to query methods (onGet) of classes marked with #[CacheableResponse].
 *
 * @see \BEAR\RepositoryModule\Annotation\CacheableResponse
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 * @internal
 */
final class DonutCacheableResponseInterceptor extends AbstractDonutCacheInterceptor
{
    /** @psalm-suppress InvalidClassConstantType */
    protected const IS_ENTIRE_CONTENT_CACHEABLE = true;
}
