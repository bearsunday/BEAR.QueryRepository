<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\DonutCacheModule;

/**
 * Enables event-driven cache invalidation for fully cacheable responses
 *
 * For content that is fundamentally static but changes predictably via resource
 * methods (onPut, onDelete, etc.). Unlike #[Cacheable] which uses TTL-based
 * expiration, this enables tag-based invalidation driven by resource dependencies.
 * The entire response is cached and receives an ETag for conditional requests,
 * enabling 304 (Not Modified) responses to reduce network transfer costs.
 *
 * Interceptors bound:
 * - DonutCacheableResponseInterceptor (onGet methods when applied to class)
 * - DonutCommandInterceptor (onPut/onPatch/onDelete methods when applied to class)
 * - DonutCacheInterceptor (when applied to method)
 *
 * @see DonutCacheModule
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class CacheableResponse
{
}
