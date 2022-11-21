<?php

declare(strict_types=1);

use Koriym\Attributes\AttributeReader;
use Ray\ServiceLocator\ServiceLocator;

$_ENV['TMP_DIR'] = __DIR__ . '/tmp';
$unlink = static function ($path) use (&$unlink): void {
    foreach ((array) glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') as $f) {
        $file = (string) $f;
        is_dir($file) ? $unlink($file) : unlink($file);
        @rmdir($file);
    }
};
$unlink($_ENV['TMP_DIR']);

// no annotation in PHP 8
if (PHP_MAJOR_VERSION >= 8) {
    ServiceLocator::setReader(new AttributeReader());
}
