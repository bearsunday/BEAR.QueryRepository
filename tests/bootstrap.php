<?php

declare(strict_types=1);

use Koriym\Attributes\AttributeReader;
use Ray\ServiceLocator\ServiceLocator;

$unlink = static function (string $path) use (&$unlink): void {
    foreach ((array) glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') as $f) {
        $file = (string) $f;
        is_dir($file) ? $unlink($file) : unlink($file);
        @rmdir($file);
    }
};
$unlink(__DIR__ . '/tmp');

// no annotation in PHP 8
if (PHP_MAJOR_VERSION >= 8) { // @phpstan-ignore-line
    ServiceLocator::setReader(new AttributeReader());
}
