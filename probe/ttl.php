<?php

declare(strict_types=1);

// StorageRedisDsnModule で fake app の page と値を 1 回ずつ GET し、Redis に入った鍵の種類と TTL を出す（6 節）
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushAll();

$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379'));
$resource = (new Injector($module, dirname(__DIR__) . '/tests/tmp'))->getInstance(ResourceInterface::class);
(string) $resource->get('page://self/html/blog-posting'); // #[CacheableResponse]
(string) $resource->get('page://self/html/blog-posting-donut'); // #[DonutCache]
$resource->get('app://self/user', ['id' => 1]); // #[Cacheable]

$rows = [];
$it = null;
while (($keys = $redis->scan($it, '*', 100)) !== false) {
    foreach ($keys as $key) {
        $rows[] = sprintf('%-50s %-6s ttl=%d', substr($key, 0, 50), $redis->type($key) === Redis::REDIS_SET ? 'SET' : 'STRING', $redis->ttl($key));
    }
}

sort($rows);
echo implode("\n", $rows), "\n";
