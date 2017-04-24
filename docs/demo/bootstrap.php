<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
use BEAR\Resource\ResourceInterface;
use Composer\Autoload\ClassLoader;
use FakeVendor\DemoApp\AppModule;
use Ray\Di\Injector;

/** @var $loader ClassLoader */
$loader = require dirname(dirname(__DIR__)) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);

/* @var $resource ResourceInterface */
$resource = (new Injector(new AppModule, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
