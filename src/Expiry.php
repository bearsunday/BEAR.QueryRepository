<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

class Expiry extends \ArrayObject
{
    /**
     * Expiry time
     *
     * @param int $short
     * @param int $medium
     * @param int $long
     */
    public function __construct($short = 60, $medium = 3600, $long = 86400)
    {
        $this['short'] = $short;
        $this['medium'] = $medium;
        $this['long'] = $long;
        $this['never'] = 0;
    }
}
