<?php

declare(strict_types=1);

use Koriym\Attributes\AttributeReader;
use Ray\ServiceLocator\ServiceLocator;

array_map('unlink', glob(__DIR__ . '/tests/tmp/*.php'));

ServiceLocator::setReader(new AttributeReader());
