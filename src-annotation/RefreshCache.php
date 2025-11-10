<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\DonutCommandInterceptor;

/**
 * Refreshes donut cache after command execution
 *
 * Interceptors bound:
 * - DonutCacheInterceptor
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#cache-invalidation
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RefreshCache
{
}
