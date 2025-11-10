<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\DonutCacheableResponseInterceptor;
use BEAR\QueryRepository\DonutCacheModule;
use BEAR\QueryRepository\DonutCommandInterceptor;

/**
 * Marks a resource for full response caching (entire content is cacheable)
 *
 * Interceptors bound:
 * - DonutCacheableResponseInterceptor (onGet methods when applied to class)
 * - DonutCommandInterceptor (onPut/onPatch/onDelete methods when applied to class)
 * - DonutCacheInterceptor (when applied to method)
 *
 * @see DonutCacheModule
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#donut-cache
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class CacheableResponse
{
}
