<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

use BEAR\QueryRepository\DonutQueryInterceptor;

/**
 * @Annotation
 * @Target("CLASS")
 *
 * @see DonutQueryInterceptor
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class DonutCache
{
}
