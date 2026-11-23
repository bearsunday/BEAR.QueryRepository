<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Purges the cache of this resource's URI after the method runs, then requests it again to rebuild the entry
 *
 * Only onPost(), onPut(), onPatch() and onDelete() run it. A #[Cacheable] or #[CacheableResponse] class ignores it; its write methods do this already.
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#usage
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RefreshCache
{
}
