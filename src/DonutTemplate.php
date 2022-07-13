<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class DonutTemplate
{
    /** @readonly */
    public static $disable = false;

    /**
     * Set refresh donut flag for AbstractDonutCacheInterceptor
     */
    public function disable(): void
    {
        self::$disable = true;
    }

    public function enable(): void
    {
        self::$disable = false;
    }
}
