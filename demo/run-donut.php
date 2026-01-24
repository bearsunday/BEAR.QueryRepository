<?php

declare(strict_types=1);

/**
 * Donut Cache Demo
 *
 * This script demonstrates donut caching (static outer + dynamic inner content):
 * - Static outer shell (donut) is cached separately
 * - Dynamic inner content can be refreshed without regenerating the whole page
 * - When inner content is invalidated, only that part is regenerated
 *
 * Resources used (from tests/Fake/fake-app):
 * - BlogPosting (outer shell) embeds Comment (inner dynamic content)
 * - Comment embeds Like
 */

use BEAR\QueryRepository\FakeEtagPoolModule;
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\QueryRepository\RepositoryLoggerInterface;
use BEAR\QueryRepository\ResourceStorageInterface;
use BEAR\QueryRepository\UriTag;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';

// Scenario descriptions (for humans)
echo <<<'SCENARIOS'
=== Donut Cache Demo ===

This demo executes the following scenarios:

1. Initial access to blog-posting
   - BlogPosting (donut outer shell) is created
   - Comment (dynamic inner) is embedded
   - Both donut structure and content are cached

2. Re-access blog-posting
   - Should be cache-hit (full page from cache)

3. Invalidate comment (inner content only)
   - Comment cache is purged
   - Donut structure remains, inner content refreshed

4. Access blog-posting after comment invalidation
   - Donut is found, but needs refresh
   - Only Comment is regenerated, donut structure reused

=== Executing... ===

SCENARIOS;

$namespace = 'FakeVendor\HelloWorld';
$module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$injector = new Injector($module, __DIR__ . '/tmp');

$resource = $injector->getInstance(ResourceInterface::class);
$repository = $injector->getInstance(QueryRepositoryInterface::class);
$storage = $injector->getInstance(ResourceStorageInterface::class);
$logger = $injector->getInstance(RepositoryLoggerInterface::class);

// Execute scenarios silently
$logger->log('request-start', ['uri' => 'page://self/html/blog-posting']);
$resource->get('page://self/html/blog-posting');                           // 1. Initial access

$logger->log('request-start', ['uri' => 'page://self/html/blog-posting']);
$resource->get('page://self/html/blog-posting');                           // 2. Re-access (cache-hit)

$logger->log('request-start', ['uri' => 'page://self/html/comment', 'method' => 'invalidate']);
$storage->invalidateTags([(new UriTag())(new Uri('page://self/html/comment'))]);  // 3. Invalidate comment

$logger->log('request-start', ['uri' => 'page://self/html/blog-posting']);
$resource->get('page://self/html/blog-posting');                           // 4. Access after invalidation

// Output logs only
echo "=== Cache Log ===" . PHP_EOL;
echo $logger . PHP_EOL;
