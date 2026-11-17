<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Bound to null and read by nothing: the stored entry holds its own ETag
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class EtagPool
{
}
