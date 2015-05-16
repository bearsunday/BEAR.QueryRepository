<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

trait HttpCacheInject
{
    /**
     * @var HttpCacheInterface
     */
    public $httpCache;

    /**
     * @param HttpCacheInterface $httpCache
     *
     * @Ray\Di\Di\Inject
     */
    public function setHttpCache(HttpCacheInterface $httpCache)
    {
        $this->httpCache = $httpCache;
    }
}
