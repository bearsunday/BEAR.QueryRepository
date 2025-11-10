<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\AnnotationToAttributeRector;
use Rector\Php80\ValueObject\AnnotationToAttribute;

/**
 * Rector configuration for migrating from Doctrine Annotations to PHP 8 Attributes
 *
 * This configuration helps users migrate their code from doctrine/annotations
 * to native PHP 8 attributes for BEAR.QueryRepository annotations.
 *
 * Usage:
 *   # Process a single directory
 *   vendor/bin/rector process src --config=vendor/bear/query-repository/rector-migrate.php --dry-run
 *   vendor/bin/rector process src --config=vendor/bear/query-repository/rector-migrate.php
 *
 *   # Process multiple directories
 *   vendor/bin/rector process src tests --config=vendor/bear/query-repository/rector-migrate.php
 */
return RectorConfig::configure()
    ->withConfiguredRule(
        AnnotationToAttributeRector::class,
        [
            // BEAR.QueryRepository Annotations
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\Cacheable'),
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\HttpCache'),
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\NoHttpCache'),
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\Refresh'),
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\Purge'),
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\DonutCache'),
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\RefreshCache'),
            new AnnotationToAttribute('BEAR\RepositoryModule\Annotation\Commands'),
        ]
    );