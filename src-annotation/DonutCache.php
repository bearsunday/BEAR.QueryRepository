<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\DonutCacheModule;

/**
 * Enables event-driven donut caching for resources with non-cacheable embedded content
 *
 * For content that is fundamentally static but changes predictably via resource
 * methods, with some embedded resources that cannot be cached. Unlike #[Cacheable]
 * which uses TTL-based expiration, this enables tag-based invalidation. Only
 * cacheable portions are stored; no ETag is generated for the entire response.
 *
 * Example:
 * ```php
 * #[DonutCache]
 * class BlogPosting extends ResourceObject
 * {
 *     #[Embed(rel: 'comment', src: 'app://self/comments')]
 *     public function onGet(int $id): static
 *     {
 *         // ...
 *     }
 * }
 * ```
 *
 * Interceptors bound:
 * - DonutCacheInterceptor (onGet methods when applied to class, or any method when applied to method)
 *
 * @see DonutCacheModule
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#donut-cache
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class DonutCache
{
}
