<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

use Stringable;

abstract class AbstractCacheControl implements Stringable
{
    abstract public function __toString(): string;
}
