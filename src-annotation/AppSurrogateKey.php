<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The key every stored entry and the Surrogate-Key of its response carry; bound by `AppSurrogateKeyModule`
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class AppSurrogateKey
{
}
