<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY)]
#[Qualifier]
final class CacheEngine
{
    public function __construct(
        public string $value,
    ) {
    }
}
