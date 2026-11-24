<?php

declare(strict_types=1);

// usage: php probe/clear-namespace.php <single|cluster>
// DSN は 1 台、redis_cluster は options で渡す形（StorageRedisDsnModule($dsn, ['redis_cluster' => 1])）で、
// deflate で保存した古い namespace を、marshaller なしで組んだ adapter の clear() で消す。
// 消さない namespace が残るかを見る。fake app の CacheNamespace は空文字なので、namespace はここで明示する。
use BEAR\QueryRepository\MarshallerProvider;
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$mode = $argv[1] ?? 'single';
[$dsn, $flag, $ports] = $mode === 'cluster' ? ['redis://127.0.0.1:7001', 1, [7001, 7002, 7003]] : ['redis://127.0.0.1:6379', 0, [6379]];
$count = static function () use ($ports): string {
    $byNs = [];
    foreach ($ports as $port) {
        $r = new Redis();
        $r->connect('127.0.0.1', $port);
        $it = null;
        while (($batch = $r->scan($it, '*', 1000)) !== false) {
            foreach ($batch as $key) {
                $byNs[explode(':', $key, 2)[0]] = ($byNs[explode(':', $key, 2)[0]] ?? 0) + 1;
            }
        }
    }
    ksort($byNs);

    return json_encode($byNs);
};
foreach ($ports as $port) {
    $r = new Redis();
    $r->connect('127.0.0.1', $port);
    $r->flushAll();
}

// この形の設定で、StorageRedisDsnModule が組む pool と接続
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new StorageRedisDsnModule($dsn, ['redis_cluster' => $flag], marshallingOptions: ['enabled' => true, 'type' => 'deflate']));
$modulePool = (new Injector($module, dirname(__DIR__) . '/tests/tmp'))->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
printf("%s: StorageRedisDsnModule の pool は %s on %s\n", $mode, $modulePool::class, get_debug_type((fn () => $this->redis)->call($modulePool)));

$deflate = (new MarshallerProvider(['enabled' => true, 'type' => 'deflate']))->get();
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);
foreach (['oldns' => 'old', 'curns' => 'cur'] as $ns => $role) {
    $pool = new RedisTagAwareAdapter(RedisAdapter::createConnection($dsn, ['redis_cluster' => $flag]), $ns, 0, $deflate);
    for ($i = 0; $i < 100; $i++) {
        $pool->save($pool->getItem('ro-page' . $i)->set($body)->tag(['t' . $i, 'shared'])) || exit("save failed\n");
    }
}
$redis = RedisAdapter::createConnection($dsn, ['redis_cluster' => $flag]);
$key = 'oldns:ro-page0';
$size = $redis instanceof RedisCluster ? $redis->rawCommand($key, 'MEMORY', 'USAGE', $key) : $redis->rawCommand('MEMORY', 'USAGE', $key);
printf("%s (%s): 本文 50,000 バイトの項目 1 本の MEMORY USAGE %d バイト\n", $mode, get_debug_type($redis), $size);
echo '  clear() の前: ', $count(), "\n";
(new RedisTagAwareAdapter(RedisAdapter::createConnection($dsn, ['redis_cluster' => $flag]), 'oldns'))->clear();
echo '  clear() の後: ', $count(), "\n";
