<?php

declare(strict_types=1);

// usage: php probe/cold-start.php <workers>
// 空のキャッシュに、同じ page への GET が同時に来たとき（デプロイ直後）に、何回描画して保存するかを数える。
// 描画の回数は保存のコマンド（Redis の SETEX）の数で見る。比べるために、1 回だけ GET したときの数も出す。
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$workers = (int) ($argv[1] ?? 12);
$uri = 'page://self/html/blog-posting'; // #[CacheableResponse]、穴は #[Cacheable] の comment
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379'));
$injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');

function setexCalls(Redis $redis): int
{
    preg_match('/calls=(\d+)/', $redis->info('commandstats')['cmdstat_setex'] ?? 'calls=0', $m);

    return (int) $m[1];
}

$admin = new Redis();
$admin->connect('127.0.0.1', 6379);
foreach ([1, $workers] as $n) {
    $admin->flushAll();
    $admin->config('RESETSTAT');
    $start = microtime(true) + 1.0;
    for ($w = 0; $w < $n; $w++) {
        if (pcntl_fork() === 0) {
            $resource = $injector->getInstance(ResourceInterface::class); // 接続は子ごとに作る
            time_sleep_until($start);
            (string) $resource->get($uri);
            exit(0);
        }
    }
    while (pcntl_wait($status) > 0);
    printf("同時に %2d 本の GET: 保存のコマンド SETEX %d 回\n", $n, setexCalls($admin));
}
