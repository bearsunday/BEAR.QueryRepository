<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Ray\Di\Di\Qualifier;

/**
 * @Annotation
 * @Target("METHOD")
 * @Qualifier()
 */
final class CacheVersion
{
    /**
     * @var string
     */
    public $value;
}
