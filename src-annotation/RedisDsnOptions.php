<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * The Redis connection options of `StorageRedisDsnModule`
 *
 * @see https://bearsunday.github.io/manuals/1.0/en/redis-dsn.html#available-options
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
#[Qualifier]
final class RedisDsnOptions
{
}
