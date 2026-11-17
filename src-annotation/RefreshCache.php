<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * Purges the cache of this resource's URI after the method runs
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#usage
 * @see \BEAR\QueryRepository\DonutCommandInterceptor
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RefreshCache
{
}
