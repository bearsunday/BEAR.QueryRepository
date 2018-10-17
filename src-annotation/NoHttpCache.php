<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

/**
 * HTTP Cache Control
 *
 * Simplified notation to say that you don't want anything cached
 *
 * @Annotation
 * @Target("CLASS")
 */
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
