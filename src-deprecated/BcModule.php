<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;
use function trigger_error;

/**
 * @deprecated
 *
 * Backward Compatibility module
 *
 * Install this when you need a deprecated interface.
 * (I don't think it' ever going to be needed, but just in case.)
 */
class BcModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        trigger_error('BEAR\QueryRepository\BcModule is deprecated.', E_USER_DEPRECATED);
    }
}
