<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The known tag versions TTL of symfony/cache `TagAwareAdapter`; nothing in this package injects it
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_METHOD)]
#[Qualifier]
final class KnownTagTtl
{
    public function __construct(
        public string $value = '',
    ) {
    }
}
