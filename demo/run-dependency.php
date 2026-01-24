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

// Scenario descriptions (for humans)
echo <<<'SCENARIOS'
=== Cache Dependency Demo ===

This demo executes the following scenarios:

1. Initial access to level-one (3-level chain)
   - LevelOne embeds LevelTwo embeds LevelThree
   - All three will be cache-miss, dependencies registered

2. Re-access level-one
   - Should be cache-hit

3. Purge level-three (grandchild)
   - Should cascade invalidate level-two and level-one

4. Re-access level-one after purge
   - All three should be cache-miss (regenerated)

5. Access ParentA and ParentB
   - Both embed ChildC (shared dependency)

6. Purge child-c
   - Should invalidate both ParentA and ParentB

7. Re-access both parents after purge
   - Both should be cache-miss (regenerated)

=== Executing... ===

SCENARIOS;

$namespace = 'FakeVendor\HelloWorld';
$injector = new Injector(
    new FakeEtagPoolModule(ModuleFactory::getInstance($namespace)),
    __DIR__ . '/tmp',
);

$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$logger = $injector->getInstance(RepositoryLoggerInterface::class);

// Execute scenarios silently
$logger->log('request-start', ['uri' => 'page://self/dep/level-one']);
$resource->get('page://self/dep/level-one');                    // 1. Initial access

$logger->log('request-start', ['uri' => 'page://self/dep/level-one']);
$resource->get('page://self/dep/level-one');                    // 2. Re-access (cache-hit)

$logger->log('request-start', ['uri' => 'page://self/dep/level-three', 'method' => 'purge']);
$repository->purge(new Uri('page://self/dep/level-three'));     // 3. Purge grandchild

$logger->log('request-start', ['uri' => 'page://self/dep/level-one']);
$resource->get('page://self/dep/level-one');                    // 4. Re-access after purge

$logger->log('request-start', ['uri' => 'page://self/dep/parent-a']);
$resource->get('page://self/dep/parent-a');                     // 5a. Access ParentA

$logger->log('request-start', ['uri' => 'page://self/dep/parent-b']);
$resource->get('page://self/dep/parent-b');                     // 5b. Access ParentB

$logger->log('request-start', ['uri' => 'page://self/dep/child-c', 'method' => 'purge']);
$repository->purge(new Uri('page://self/dep/child-c'));         // 6. Purge shared child

$logger->log('request-start', ['uri' => 'page://self/dep/parent-a']);
$resource->get('page://self/dep/parent-a');                     // 7a. Re-access ParentA

$logger->log('request-start', ['uri' => 'page://self/dep/parent-b']);
$resource->get('page://self/dep/parent-b');                     // 7b. Re-access ParentB

// Output logs only
echo "=== Cache Log ===" . PHP_EOL;
echo $logger . PHP_EOL;
