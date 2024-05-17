<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation\NamedArgumentConstructor;
use Ray\Di\Di\Qualifier;

/**
 * @Annotation
 * @Target("METHOD")
 * @Qualifier()
 * @NamedArgumentConstructor()
 */
#[Attribute(Attribute::TARGET_METHOD)]
#[Qualifier]
final class Redis
{
    public function __construct(
        public string $value,
    ) {
    }
}
