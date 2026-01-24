<?php

declare(strict_types=1);

/**
 * Cache Dependency Demo
 *
 * This script demonstrates cache dependency logging to help understand:
 * - Cache hit/miss operations
 * - Dependency registration (depends-on)
 * - Cascade invalidation (invalidate-etag)
 *
 * Resources used (from tests/Fake/fake-app):
 * - LevelOne -> LevelTwo -> LevelThree (3-level dependency chain)
 * - ParentA, ParentB -> ChildC (multiple parents depend on same child)
 */

use BEAR\QueryRepository\FakeEtagPoolModule;
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\QueryRepository\RepositoryLoggerInterface;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';

$namespace = 'FakeVendor\HelloWorld';
$injector = new Injector(
    new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)),
    __DIR__ . '/tmp',
);

$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$logger = $injector->getInstance(RepositoryLoggerInterface::class);

echo "=== Cache Dependency Demo ===" . PHP_EOL . PHP_EOL;

// Scenario 1: 3-level dependency chain
echo "--- Scenario 1: Initial access to level-one (3-level chain) ---" . PHP_EOL;
echo "Accessing page://self/dep/level-one" . PHP_EOL;
echo "  LevelOne embeds LevelTwo embeds LevelThree" . PHP_EOL . PHP_EOL;
$resource->get('page://self/dep/level-one');

echo "--- Scenario 2: Re-access level-one (should be cache-hit) ---" . PHP_EOL;
$resource->get('page://self/dep/level-one');
echo "  Cache hit - resource served from cache" . PHP_EOL . PHP_EOL;

echo "--- Scenario 3: Purge level-three (grandchild) ---" . PHP_EOL;
echo "Purging page://self/dep/level-three" . PHP_EOL;
echo "  Should cascade invalidate level-two and level-one" . PHP_EOL . PHP_EOL;
$repository->purge(new Uri('page://self/dep/level-three'));

echo "--- Scenario 4: Re-access level-one after purge (should be cache-miss) ---" . PHP_EOL;
$resource->get('page://self/dep/level-one');
echo "  All three levels regenerated" . PHP_EOL . PHP_EOL;

echo "--- Scenario 5: Multiple parents depend on same child ---" . PHP_EOL;
echo "Accessing ParentA and ParentB (both embed ChildC)" . PHP_EOL . PHP_EOL;
$resource->get('page://self/dep/parent-a');
$resource->get('page://self/dep/parent-b');

echo "--- Scenario 6: Purge child-c (shared dependency) ---" . PHP_EOL;
echo "Purging page://self/dep/child-c" . PHP_EOL;
echo "  Should invalidate both ParentA and ParentB" . PHP_EOL . PHP_EOL;
$repository->purge(new Uri('page://self/dep/child-c'));

echo "--- Scenario 7: Re-access both parents after purge ---" . PHP_EOL;
$resource->get('page://self/dep/parent-a');
$resource->get('page://self/dep/parent-b');
echo "  Both parents regenerated" . PHP_EOL . PHP_EOL;

echo "=== Cache Log ===" . PHP_EOL;
echo $logger . PHP_EOL;
