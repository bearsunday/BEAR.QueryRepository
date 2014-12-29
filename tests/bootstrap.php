<?php

// loader
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
//
//$_ENV['TEST_DIR'] = __DIR__;
$_ENV['TMP_DIR'] = __DIR__ . '/tmp';
//$_ENV['PACKAGE_DIR'] = dirname(__DIR__);
//
$unlink = function ($path) use (&$unlink) {
    foreach (glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') as $file) {
        is_dir($file) ? $unlink($file) : unlink($file);
        @rmdir($file);
    }
};
$unlink($_ENV['TMP_DIR']);
