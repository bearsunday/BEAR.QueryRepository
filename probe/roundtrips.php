<?php

declare(strict_types=1);

// usage: php probe/roundtrips.php <single|cluster>
// 保存と読みで、サーバーが受けたコマンド（種類別）と読み取りイベント（total_reads_processed ≒ 往復）を数える
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
$dsn = $mode === 'cluster'
    ? 'redis:?host[127.0.0.1:7001]&host[127.0.0.1:7002]&host[127.0.0.1:7003]&redis_cluster=1'
    : 'redis://127.0.0.1:6379';
$ports = $mode === 'cluster' ? [7001, 7002, 7003] : [6379];
$admin = [];
foreach ($ports as $port) {
    $admin[$port] = new Redis();
    $admin[$port]->connect('127.0.0.1', $port);
    $admin[$port]->flushAll();
}

$options = ['enabled' => true, 'type' => 'deflate'];
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new StorageRedisDsnModule($dsn, [], 0, $options));
$injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');
$fromInjector = static fn (): TagAwareAdapterInterface => $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);

/** @return array{commands: array<string, int>, reads: int} */
function snapshot(array $admin): array
{
    $commands = [];
    $reads = 0;
    foreach ($admin as $r) {
        foreach ($r->info('commandstats') as $name => $stat) {
            preg_match('/calls=(\d+)/', $stat, $m);
            $commands[substr($name, 8)] = ($commands[substr($name, 8)] ?? 0) + (int) $m[1];
        }

        $reads += (int) $r->info('stats')['total_reads_processed'];
    }

    return ['commands' => $commands, 'reads' => $reads];
}

/** 計測用の INFO 呼び出し（ノードごとに 2 回）を除いた、1 回あたりの差 */
function per(array $before, array $after, int $n, int $nodes): string
{
    $diff = [];
    foreach ($after['commands'] as $name => $calls) {
        $d = $calls - ($before['commands'][$name] ?? 0) - ($name === 'info' ? 2 * $nodes : 0);
        if ($d > 0) {
            $diff[] = sprintf('%s %.1f', $name, $d / $n);
        }
    }

    return sprintf('往復 %.1f / コマンド %s', ($after['reads'] - $before['reads'] - 2 * $nodes) / $n, implode(', ', $diff));
}

function tags(int $i): array
{
    mt_srand($i);
    $tags = [];
    while (count($tags) < 20) { $tags['s' . mt_rand(0, 199)] = true; }
    while (count($tags) < 100) { $tags['r' . mt_rand(0, 4999)] = true; }

    return array_keys($tags);
}

$n = 50;
$pool = $fromInjector();
$pool->save($pool->getItem('warmup')->set('x')); // 最初の保存の eviction policy の問い合わせを外す
$b = snapshot($admin);
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $pool->save($pool->getItem('ro-page' . $i)->set($body)->tag(tags($i))->expiresAfter(31_536_000));
}
printf("%-8s 保存（同じ pool）       %.3fms  %s\n", $mode, (hrtime(true) - $t) / 1e6 / $n, per($b, snapshot($admin), $n, count($ports)));

$readers = [
    'injector から毎回取る' => $fromInjector,
    '同じ pool を使い回す' => static fn (): TagAwareAdapterInterface => $pool,
    '接続を共有し adapter だけ毎回作る' => (static function () use ($dsn, $options, $pool): Closure {
        $connection = RedisAdapter::createConnection($dsn);
        $marshaller = (new MarshallerProvider($options))->get();
        $namespace = rtrim((fn (): string => $this->namespace)->call($pool), ':');

        return static fn (): TagAwareAdapterInterface => new RedisTagAwareAdapter($connection, $namespace, 0, $marshaller);
    })(),
];
foreach ($readers as $label => $get) {
    $get(); // 接続を先に作っておく
    $b = snapshot($admin);
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $get()->getItem('ro-page' . $i)->isHit() || exit("miss $label $i\n");
    }
    printf("%-8s 読み（%s） %.3fms  %s\n", $mode, $label, (hrtime(true) - $t) / 1e6 / $n, per($b, snapshot($admin), $n, count($ports)));
}
