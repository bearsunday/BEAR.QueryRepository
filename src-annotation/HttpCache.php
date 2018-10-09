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
 * Builds a complex Cache-Control header
 *
 * @Annotation
 * @Target("CLASS")
 * {@inheritdoc}
 */
final class HttpCache extends AbstractCacheControl
{
    /**
     * @var bool
     */
    public $isPrivate = false;

    /**
     * @var bool
     */
    public $noCache = false;

    /**
     * @var bool
     */
    public $noStore = false;

    /**
     * @var bool
     */
    public $mustRevalidate = false;

    /**
     * @var int
     */
    public $maxAge = 0;

    /**
     * @var int
     */
    public $sMaxAge = 0;

    public function __toString()
    {
        $control = $this->isPrivate ? ['private'] : ['public'];
        if ($this->noCache) {
            $control[] = 'no-cache';
        }
        if ($this->noStore) {
            $control[] = 'no-store';
        }
        if ($this->mustRevalidate) {
            $control[] = 'must-revalidate';
        }
        if ($this->maxAge) {
            $control[] = \sprintf('max-age=%d', $this->maxAge);
        }
        if ($this->sMaxAge) {
            $control[] = \sprintf('s-maxage=%d', $this->sMaxAge);
        }

        return  \implode(', ', $control);
    }
}
