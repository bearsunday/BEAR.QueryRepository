<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The pool that keeps the tag versions; null keeps them in the `ResourceObjectPool` adapter
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class TagsPool
{
}
