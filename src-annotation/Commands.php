<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * @Annotation
 * @Target("METHOD")
 * @Qualifier
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER) , Qualifier]
final class Commands
{
}
