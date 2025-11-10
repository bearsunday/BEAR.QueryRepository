<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Marks a resource class as cacheable with TTL-based expiration
 *
 * Traditional time-based caching where cache expires after a specified duration.
 * For event-driven cache invalidation based on resource dependencies, use
 * #[CacheableResponse] or #[DonutCache] instead.
 *
 * Example:
 * ```php
 * // Cache for 1 hour
 * #[Cacheable(expirySecond: 3600)]
 *
 * // Cache with predefined expiry preset
 * #[Cacheable(expiry: 'short')]  // short, medium, long, never
 *
 * // Cache until specific time from body field
 * #[Cacheable(expiryAt: 'expires_at')]
 * ```
 *
 * Interceptors bound:
 * - CacheInterceptor (onGet methods)
 * - CommandInterceptor (onPut/onPatch/onDelete methods)
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#cacheable
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Cacheable
{
    /**
     * @param 'short'|'medium'|'long'|'never' $expiry
     * @param 'value'|'view'                  $type
     */
    public function __construct(
        public string $expiry = 'never',
        public int $expirySecond = 0,
        public string $expiryAt = '',
        public bool $update = false,
        public string $type = 'value',
    ) {
    }
}
