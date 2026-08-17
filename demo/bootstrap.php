<?php

declare(strict_types=1);

use BEAR\Resource\ResourceInterface;
use FakeVendor\DemoApp\AppModule;
use Ray\Di\Injector;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);

/** @var ResourceInterface $resource */
$resource = (new Injector(new AppModule(), __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
