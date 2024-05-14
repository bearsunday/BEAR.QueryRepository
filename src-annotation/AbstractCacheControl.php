<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

abstract class AbstractCacheControl implements \Stringable
{
    abstract function __toString(): string;
}
