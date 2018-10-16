<?php

declare(strict_types=1);

$_ENV['TMP_DIR'] = __DIR__ . '/tmp';
$unlink = function ($path) use (&$unlink) {
    foreach (\glob(\rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') as $file) {
        \is_dir($file) ? $unlink($file) : \unlink($file);
        @\rmdir($file);
    }
};
$unlink($_ENV['TMP_DIR']);
