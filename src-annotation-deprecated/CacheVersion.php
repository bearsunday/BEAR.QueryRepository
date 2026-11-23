<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use Ray\Di\Di\Qualifier;

/**
 * @deprecated
 *
 * Use \Ray\PsrCacheModule\Annotation\CacheNamespace
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER), Qualifier]
final class CacheVersion
{
    public function __construct(public string $value)
    {
    }
}
