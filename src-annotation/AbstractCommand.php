<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Doctrine\Common\Annotations\NamedArgumentConstructorAnnotation;

abstract class AbstractCommand implements NamedArgumentConstructorAnnotation
{
    public function __construct(public string $uri)
    {
    }
}
