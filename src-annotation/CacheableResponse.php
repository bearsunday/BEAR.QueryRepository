<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\DonutCacheModule;

/**
 * Caches the response (its representation)
 *
 * For content that is fundamentally static but changes predictably via resource
 * methods (onPut, onDelete, etc.). The entire response is cached and receives an ETag
 * for conditional requests, enabling 304 (Not Modified) responses to reduce network
 * transfer costs.
 *
 * Example:
 * ```php
 * #[CacheableResponse]
 * class Article extends ResourceObject
 * {
 *     public function onGet(int $id): static
 *     {
 *         // ...
 *     }
 *
 *     #[RefreshCache]
 *     public function onDelete(int $id): static
 *     {
 *         // ...
 *     }
 * }
 * ```
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
