<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Ray\Di\Di\Inject;

/** @deprecated Use constructor injection instead */
trait HttpCacheInject // @phpstan-ignore-line
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
