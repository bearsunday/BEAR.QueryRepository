<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\RefreshInterceptor;

/**
 * @Annotation
 * @Target("METHOD")
 *
 * @see RefreshInterceptor
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Refresh extends AbstractCommand
{
}
