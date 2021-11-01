<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class DonutCacheableResponseInterceptor extends AbstractDonutCacheInterceptor
{
    protected const IS_ENTIRE_CONTENT_CACHEABLE = true;
}
