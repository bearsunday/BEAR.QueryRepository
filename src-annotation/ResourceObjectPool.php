<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The cache pool that stores the resource entries
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class ResourceObjectPool
{
}
