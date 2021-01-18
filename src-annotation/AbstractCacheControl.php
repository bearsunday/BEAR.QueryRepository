<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Doctrine\Common\Annotations\NamedArgumentConstructorAnnotation;

abstract class AbstractCacheControl implements NamedArgumentConstructorAnnotation
{
    abstract function __toString();
}
