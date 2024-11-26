<?php

declare(strict_types=1);

use Koriym\Attributes\AttributeReader;
use Ray\ServiceLocator\ServiceLocator;

array_map('unlink', glob(__DIR__ . '/tests/tmp/*.php'));

// no annotation in PHP 8
if (PHP_MAJOR_VERSION >= 8) { // @phpstan-ignore-line
    ServiceLocator::setReader(new AttributeReader());
}
