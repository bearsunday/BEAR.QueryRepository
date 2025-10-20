<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * Annotation for marshaller options configuration
 *
 * Used to inject marshalling configuration options for Redis cache adapters.
 * Supports compression, encryption, and serialization options.
 */
#[Attribute]
#[Qualifier]
final class MarshallerOptions
{
}
