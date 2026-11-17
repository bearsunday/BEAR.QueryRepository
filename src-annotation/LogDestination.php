<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The writer a kept session is handed to, which `PolicyLogWriter` wraps
 *
 * `PolicyLogWriter` is itself a `LogWriterInterface`, so the inner writer needs a name of its own.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class LogDestination
{
}
