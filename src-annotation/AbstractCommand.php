<?php

declare(strict_types=1);

namespace BEAR\RepositoryModule\Annotation;

abstract class AbstractCommand
{
    public function __construct(
        public string $uri,
    ) {
    }
}
