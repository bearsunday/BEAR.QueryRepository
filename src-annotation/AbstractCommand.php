<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

abstract class AbstractCommand
{
    /**
     * @var string
     */
    public $uri = false;
}
