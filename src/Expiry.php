<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Expiry time value object
 */
class Expiry extends \ArrayObject
{
    public function __construct(int $short = 60, int $medium = 3600, int $long = 86400)
    {
        $this['short'] = $short;
        $this['medium'] = $medium;
        $this['long'] = $long;
        $this['never'] = 0;
        parent::__construct();
    }
}
