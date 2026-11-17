<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The logger this package records the cache session into
 *
 * `Koriym\SemanticLogger\SemanticLoggerInterface` is not ours: an application may already bind it
 * for its own session (Be Framework records a becoming tree into the same interface), and Ray.Di
 * keeps the binding that lands first. The cache log therefore has a name of its own, and an
 * application that wants both trees in one session binds this key to its logger.
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/event-sourcing.html#one-tree-with-bearqueryrepository
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class CacheLog
{
}
