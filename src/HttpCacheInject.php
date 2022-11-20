<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Ray\Di\Di\Inject;

trait HttpCacheInject
{
    /** @var HttpCacheInterface */
    public $httpCache;

    /** @Inject */
    #[Inject]
    public function setHttpCache(HttpCacheInterface $httpCache): void
    {
        $this->httpCache = $httpCache;
    }
}
