<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Caches the response apart from its non-cacheable embedded resources, which run on every request
 *
 * No ETag is given to the whole response.
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
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#usage
 * @see \BEAR\QueryRepository\DonutCacheInterceptor
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class DonutCache
{
}
