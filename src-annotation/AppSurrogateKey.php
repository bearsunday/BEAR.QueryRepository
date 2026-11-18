<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The key every entry and every Surrogate-Key of the application carries; bound by `AppSurrogateKeyModule`
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class AppSurrogateKey
{
}
