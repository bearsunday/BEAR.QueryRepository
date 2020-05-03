<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Expiry time value object
 */
class Expiry extends \ArrayObject
{
    public function __construct(int $short = 60, int $medium = 3600, int $long = 86400, int $never = 31536000)
    {
        $this['short'] = $short;
        $this['medium'] = $medium;
        $this['long'] = $long;
        $this['never'] = $never;
        parent::__construct();
    }
}
