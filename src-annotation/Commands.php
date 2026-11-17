<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The commands `CommandInterceptor` runs after a write method of a `#[Cacheable]` resource
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class Commands
{
}
