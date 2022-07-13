<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class DonutTemplate
{
    /** @readonly */
    public bool $disable = false;

    /**
     * Set refresh donut flag for AbstractDonutCacheInterceptor
     */
    public function disable(): void
    {
        $this->disable = true;
    }
}
