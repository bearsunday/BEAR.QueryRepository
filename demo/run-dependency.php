<?php

declare(strict_types=1);

/**
 * Cache Dependency Demo
 *
 * This script demonstrates cache dependency logging to help understand:
 * - Cache hit/miss operations
 * - Dependency registration (depends-on)
 * - Command-driven invalidation (a write opens a command scope)
 * - Cascade invalidation (invalidate-etag)
 * - Manual purge (a direct purge() call roots a manual_purge scope)
 *
 * Resources used (from tests/Fake/fake-app):
 * - LevelOne -> LevelTwo -> LevelThree (3-level dependency chain)
 * - ParentA, ParentB -> ChildC (multiple parents depend on same child)
 */

use BEAR\QueryRepository\FakeEtagPoolModule;
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Koriym\SemanticLogger\Stree\RenderConfig;
use Koriym\SemanticLogger\Stree\TreeRenderer;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/validate.php';

// Scenario descriptions (for humans)
echo <<<'SCENARIOS'
=== Cache Dependency Demo ===

This demo executes the following scenarios:

1. Initial access to level-one (3-level chain)
   - LevelOne embeds LevelTwo embeds LevelThree
   - All three will be cache-miss, dependencies registered

2. Re-access level-one
   - Should be cache-hit

3. Write to level-three (PUT)
   - The command purges the written resource and refreshes it in place
   - The surrogate-key cascade busts level-two and level-one
   - The log shows a command scope (method/annotations/source)
     driving the purge — cause and effect in one subtree

4. Re-access level-one after the write
   - level-one and level-two are cache-miss (rebuilt from the
     refreshed leaf, which is served from cache)

5. Access ParentA and ParentB
   - Both embed ChildC (shared dependency)

6. Purge child-c (manual repository purge)
   - Should invalidate both ParentA and ParentB
   - A direct purge() roots a top-level manual_purge scope —
     a different entry kind than the command scope in 3

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
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

// Execute scenarios. Embedded child GETs nest under their parent GET, so the
// log's open/close tree IS the embed/dependency tree (no reconstruction).
$resource->get('page://self/dep/level-one');                // 1. Initial access (cache-miss chain)
$resource->get('page://self/dep/level-one');                // 2. Re-access (cache-hit)
$resource->put('page://self/dep/level-three');              // 3. Write: command-driven cascade
$resource->get('page://self/dep/level-one');                // 4. Re-access after the write (rebuilt)
$resource->get('page://self/dep/parent-a');                 // 5a. Access ParentA
$resource->get('page://self/dep/parent-b');                 // 5b. Access ParentB
$repository->purge(new Uri('page://self/dep/child-c'));     // 6. Manual purge (manual_purge scope)
$resource->get('page://self/dep/parent-a');                 // 7a. Re-access ParentA
$resource->get('page://self/dep/parent-b');                 // 7b. Re-access ParentB

$log = $logger->flush();

// Human/AI-readable tree (open = embed scope, close = hit/miss, events = saves/invalidations)
echo '=== Cache Log Tree ===' . PHP_EOL;
echo (new TreeRenderer(new RenderConfig(true, 0.0, 1000, true)))->render($log) . PHP_EOL;

// Machine-readable JSON conforming to the published schemas (validated below
// against the local schema files; also: `vendor/bin/stree <file>`)
echo PHP_EOL . '=== Cache Log JSON ===' . PHP_EOL;
echo json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

// The demo verifies itself: the flushed log must validate offline against
// docs/schemas/context (exits non-zero on any violation)
validateLog($log);
