<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Doctrine\Common\Annotations\NamedArgumentConstructorAnnotation;

abstract class AbstractCommand implements NamedArgumentConstructorAnnotation
{
    /**
     * @var string
     */
    public $uri = false;

    public function __construct(string $uri)
    {
        $this->uri = $uri;
    }
}
