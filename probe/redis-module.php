<?php

declare(strict_types=1);

// StorageRedisDsnModule が組む ResourceObjectPool に、redis-deflate.php と同じ 1000 page を保存したときの used_memory
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);
foreach (['module default' => [], 'module deflate' => ['enabled' => true, 'type' => 'deflate']] as $name => $marshalling) {
    $redis->flushAll();
    $base = (int) $redis->info('memory')['used_memory'];
    $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
    $module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379', [], 0, $marshalling));
    $pool = (new Injector($module, dirname(__DIR__) . '/tests/tmp'))->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
    for ($i = 0; $i < 1000; $i++) {
        mt_srand($i);
        $tags = [];
        while (count($tags) < 20) { $tags['s' . mt_rand(0, 199)] = true; }
        while (count($tags) < 100) { $tags['r' . mt_rand(0, 4999)] = true; }
        $pool->save($pool->getItem('ro-page' . $i)->set($body)->tag(array_keys($tags))->expiresAfter(31_536_000)) || exit("save failed\n");
    }
    printf("%-15s %s used_memory=%.1fMB\n", $name, $pool::class, ((int) $redis->info('memory')['used_memory'] - $base) / 1048576);
}
