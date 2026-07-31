<?php

declare(strict_types=1);

use BEAR\Resource\ResourceInterface;
use FakeVendor\DemoApp\AppModule;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Koriym\SemanticLogger\Stree\RenderConfig;
use Koriym\SemanticLogger\Stree\TreeRenderer;
use Ray\Di\Injector;

function echoRo(BEAR\Resource\ResourceObject $ro)
{
    echo $ro->uri->method . PHP_EOL;
    echo $ro->code . PHP_EOL;
    foreach ($ro->headers as $headerKey => $headerValue) {
        echo "{$headerKey}:{$headerValue}" . PHP_EOL;
    }
    echo $ro . PHP_EOL;
}

/* @var $loader \Composer\Autoload\ClassLoader */
$loader = require \dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);
require __DIR__ . '/validate.php';

$injector = new Injector(new AppModule, __DIR__ . '/tmp');
/* @var $resource ResourceInterface */
$resource = $injector->getInstance(ResourceInterface::class);

echoRo($resource->uri('app://self/user')(['id' => 1])); // create cache

echoRo($resource->uri('app://self/user')(['id' => 1])); // return cache

echoRo($resource->patch->uri('app://self/user')(['id' => 1, 'name' => 'kuma'])); // recreate cache

echoRo($resource->uri('app://self/user')(['id' => 1])); // return cache

echoRo($resource->uri('app://self/user')(['id' => 1])); // return cache

// The semantic cache log of the session above: an open/event/close tree
// (GET scopes, the onPatch command scope, saves and hits) plus the
// schema-conforming JSON. This is the machine-verifiable view of the
// TTL-less, event-driven cache the HTTP output only hints at via Age.
$log = $injector->getInstance(SemanticLoggerInterface::class)->flush();

echo PHP_EOL . "=== Cache Log Tree ===" . PHP_EOL;
echo (new TreeRenderer())->render($log->toArray(), new RenderConfig(true, 0.0, 1000, true)) . PHP_EOL;

echo PHP_EOL . "=== Cache Log JSON ===" . PHP_EOL;
echo json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

validateLog($log);
