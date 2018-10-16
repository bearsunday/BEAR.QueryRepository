<?php

declare(strict_types=1);

use BEAR\Resource\ResourceInterface;
use Composer\Autoload\ClassLoader;
use FakeVendor\DemoApp\AppModule;
use Ray\Di\Injector;

/** @var $loader ClassLoader */
$loader = require \dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);

/* @var $resource ResourceInterface */
$resource = (new Injector(new AppModule, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
