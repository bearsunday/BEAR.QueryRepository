<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The logger this package records the cache session into
 *
 * It has a name of its own so that an application's own `SemanticLoggerInterface` binding stays
 * untouched; bind this key to that logger to record both in one tree.
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/event-sourcing.html#one-tree-with-bearqueryrepository
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class CacheLog
{
}
