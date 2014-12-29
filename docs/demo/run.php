<?php

use BEAR\QueryRepository\QueryRepositoryModule;
use BEAR\Resource\ResourceClientFactory;
use Composer\Autoload\ClassLoader;

/** @var $loader ClassLoader*/
$loader = require dirname(dirname(__DIR__)) . '/vendor/autoload.php';
$loader->addPsr4('FakeVendor\DemoApp\\', __DIR__);

$resource = (new ResourceClientFactory)->newClient(__DIR__ . '/tmp', 'FakeVendor\DemoApp', new QueryRepositoryModule('FakeVendor\DemoApp'));

echo PHP_EOL;
echo 'GET (onGet)' . PHP_EOL;
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user . PHP_EOL . PHP_EOL;

echo 'GET (Repository)' . PHP_EOL;
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user . PHP_EOL . PHP_EOL;

echo 'UPDATE (Repository entry reloaded)' . PHP_EOL;
$user = $resource->patch->uri('app://self/user')->withQuery(['id' => 1, 'name' => 'kuma'])->eager->request();
echo PHP_EOL;

echo 'GET (Repository)' . PHP_EOL;
$user = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
echo $user . PHP_EOL . PHP_EOL;
