<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Attribute;
use BEAR\QueryRepository\HttpCacheInterceptor;

/**
 * HTTP Cache Control
 *
 * Simplified notation to say that you don't want anything cached
 *
 * @Annotation
 * @Target("CLASS")
 *
 * @see HttpCacheInterceptor
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class NoHttpCache extends AbstractCacheControl
{
    /**
     * @return string
     */
    public function __toString()
    {
        return 'private, no-store, no-cache, must-revalidate';
    }
}
