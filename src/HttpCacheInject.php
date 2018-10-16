<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;

trait HttpCacheInject
{
    /**
     * @var HttpCacheInterface
     */
    public $httpCache;

    /**
     * @Ray\Di\Di\Inject
     */
    public function setHttpCache(HttpCacheInterface $httpCache)
    {
        $this->httpCache = $httpCache;
    }
}
