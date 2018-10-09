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
 * {@inheritdoc}
 */
final class HttpNoCache extends AbstractCacheControl
{
    /**
     * @var int
     */
    public $maxAge = 0;

    /**
     * @var int
     */
    public $sMaxAge = 0;

    /**
     * @var false
     */
    public $isPrivate = false;

    /**
     * @var bool
     */
    public $noCache = true;

    /**
     * @var bool
     */
    public $noStore = true;

    /**
     * @var bool
     */
    public $mustRevalidate = true;

    /**
     * @return string
     */
    public function __toString()
    {
        return 'private, no-store, no-cache, must-revalidate';
    }
}
