<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\Set\PHPUnitSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/demo',
        __DIR__ . '/src',
        __DIR__ . '/src-annotation',
        __DIR__ . '/src-annotation-deprecated',
        __DIR__ . '/tests',
        __DIR__ . '/tests-pecl-ext',
        __DIR__ . '/tests-php8',
    ])
    // uncomment to reach your current PHP version
     ->withPhpSets()
    ->withSets([
        PHPUnitSetList::PHPUNIT_110,
    ])
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0);
