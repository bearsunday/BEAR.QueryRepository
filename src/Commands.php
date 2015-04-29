<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

class Commands extends \ArrayObject
{
    /**
     * @param CommandInterface[] $commands
     */
    public function __construct(array $commands)
    {
        parent::__construct($commands);
    }
}
