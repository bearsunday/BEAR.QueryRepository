<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use Ray\Di\AbstractModule;
use Ray\Di\Scope;

class MobileEtagModule extends AbstractModule
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->bind(EtagSetterInterface::class)->to(MobileEtagSetter::class)->in(Scope::SINGLETON);
    }
}
