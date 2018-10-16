<?php

declare(strict_types=1);

use BEAR\Resource\ResourceInterface;
use FakeVendor\DemoApp\AppModule;
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

/* @var $resource ResourceInterface */
$resource = (new Injector(new AppModule, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);

echoRo($resource->uri('app://self/user')(['id' => 1])); // create cache

echoRo($resource->uri('app://self/user')(['id' => 1])); // return cache

echoRo($resource->patch->uri('app://self/user')(['id' => 1, 'name' => 'kuma'])); // recreate cache

echoRo($resource->uri('app://self/user')(['id' => 1])); // return cache

echoRo($resource->uri('app://self/user')(['id' => 1])); // return cache
