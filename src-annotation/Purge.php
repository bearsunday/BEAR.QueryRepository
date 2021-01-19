<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;

/**
 * @Annotation
 * @Target("METHOD")
 *
 * @see RefreshInterceptor
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Purge extends AbstractCommand
{
}
