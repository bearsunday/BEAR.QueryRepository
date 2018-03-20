<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
use BEAR\Resource\ResourceInterface;
use FakeVendor\DemoApp\AppModule;
use Ray\Di\Injector;

/* @var $loader \Composer\Autoload\ClassLoader */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);

/* @var $resource ResourceInterface */
$resource = (new Injector(new AppModule, __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);

echo 'GET' . PHP_EOL;
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user->code . $user . PHP_EOL . PHP_EOL;

echo 'GET' . PHP_EOL;
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user->code . $user . PHP_EOL . PHP_EOL;

echo 'PATCH' . PHP_EOL;
$user = $resource->patch->uri('app://self/user')->withQuery(['id' => 1, 'name' => 'kuma'])->eager->request();
echo $user->code . $user . PHP_EOL . PHP_EOL;

echo 'GET' . PHP_EOL;
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user->code . $user . PHP_EOL . PHP_EOL;

echo 'GET' . PHP_EOL;
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user->code . $user . PHP_EOL . PHP_EOL;
