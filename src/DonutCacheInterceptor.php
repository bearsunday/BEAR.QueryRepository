<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

class DonutCacheInterceptor extends DonutQueryInterceptor
{
    protected const IS_ENTIRE_CONTENT_CACHEABLE = false;
}
