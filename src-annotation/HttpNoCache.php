<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\RepositoryModule\Annotation;

/**
 * HTTP Cache Control
 *
 * Simplified notation to say that you don't want anything cached
 *
 * @Annotation
 * @Target("CLASS")
 */
final class HttpNoCache extends AbstractCacheControl
{
    /**
     * @return string
     */
    public function __toString()
    {
        return 'private, no-store, no-cache, must-revalidate';
    }
}
