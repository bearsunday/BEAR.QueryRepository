<?php

declare(strict_types=1);

/**
 * Cache Kinds Demo
 *
 * Each kind but two answers the same three requests: the first GET stores it, the
 * second is served from the cache, and a conditional GET with the ETag the client
 * holds is answered 304 from the stored entry without running the resource. C's body
 * cannot be stored, so its one request fails instead of completing a cycle; E's
 * #[DonutCache] page carries no ETag, so it stops after the second request.
 */

use BEAR\QueryRepository\ModuleFactory;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Composer\Autoload\ClassLoader;
use FakeVendor\DemoApp\AppModule;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);
require __DIR__ . '/validate.php';

echo <<<'SCENARIOS'
=== Cache Kinds Demo ===

Each kind but C and E is requested three times: GET (miss, stored), GET (hit), and a
conditional GET with the held ETag (304, cache_hit{layer: etag}).

A. #[Cacheable] value          app://self/article       -> save_value, Cache-Control: max-age=0
B. #[Cacheable(type: 'view')]  app://self/article-view  -> save_view
C. #[Cacheable] body that cannot be stored               -> cache_error{write}, the request fails
D. #[CacheableResponse]        page://self/html/blog-posting       -> put_donut, max-age=0
E. #[DonutCache]               page://self/html/blog-posting-donut -> the page is recomposed from
                                  the cached template and content on every request: it carries
                                  no ETag or Cache-Control, so a conditional GET is not answered 304

=== Executing... ===

SCENARIOS;

$requestThreeTimes = static function (Injector $injector, string $label, string $uri): void {
    $resource = $injector->getInstance(ResourceInterface::class);
    $httpCache = $injector->getInstance(HttpCacheInterface::class);
    $describe = static fn (ResourceObject $ro): string => sprintf(
        '%d, Cache-Control: %s',
        $ro->code,
        $ro->headers['Cache-Control'] ?? '(none)',
    );

    $first = $resource->get($uri);
    echo "{$label} GET {$uri} (miss) -> {$describe($first)}" . PHP_EOL;
    $second = $resource->get($uri);
    echo "{$label} GET {$uri} (hit)  -> {$describe($second)}" . PHP_EOL;
    if (! isset($second->headers['ETag'])) {
        echo "{$label} no ETag to revalidate with" . PHP_EOL;

        return;
    }

    $notModified = $httpCache->isNotModified(['HTTP_IF_NONE_MATCH' => $second->headers['ETag']]);
    echo "{$label} conditional GET with the held ETag -> " . ($notModified ? '304' : '200') . PHP_EOL;
};

// ------------------------------------------------------------- app:// resources
$injector = new Injector(new AppModule(), __DIR__ . '/tmp');
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$requestThreeTimes($injector, 'A', 'app://self/article?id=1');
$requestThreeTimes($injector, 'B', 'app://self/article-view?id=1');

try {
    $injector->getInstance(ResourceInterface::class)->get('app://self/report');
    echo 'C unexpected: the body was stored' . PHP_EOL;
} catch (Exception $e) {
    echo sprintf('C GET app://self/report -> %s: %s', $e::class, $e->getMessage()) . PHP_EOL;
}

reportLog($logger->flush(), 'A-C. #[Cacheable]');

// --------------------------------------------------------------- page:// resources
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$injector = new Injector($module, __DIR__ . '/tmp');
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

$requestThreeTimes($injector, 'D', 'page://self/html/blog-posting');
$requestThreeTimes($injector, 'E', 'page://self/html/blog-posting-donut');

reportLog($logger->flush(), 'D-E. donut pages');
