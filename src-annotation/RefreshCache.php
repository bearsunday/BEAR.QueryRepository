<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\DonutCommandInterceptor;

/**
 * @Annotation
 * @Target("METHOD")
 *
 * @see DonutCommandInterceptor
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RefreshCache
{
}
