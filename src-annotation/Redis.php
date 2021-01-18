<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * @Annotation
 * @Target("METHOD")
 * @Qualifier()
 */
#[Attribute(Attribute::TARGET_METHOD), Qualifier]
final class Redis
{
    /**
     * @var string
     */
    public $value;
}
