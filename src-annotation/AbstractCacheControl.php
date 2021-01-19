<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Doctrine\Common\Annotations\NamedArgumentConstructorAnnotation;

abstract class AbstractCacheControl
{
    abstract function __toString();
}
