<?php

declare(strict_types=1);

// usage: php probe/cluster.php <single|cluster>
// StorageRedisDsnModule（deflate）が組む pool で、保存・読み・tag の無効化・clear() を、単体の Redis と 3 台の cluster で比べる
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$mode = $argv[1] ?? 'cluster';
$dsn = $mode === 'cluster'
    ? 'redis:?host[127.0.0.1:7001]&host[127.0.0.1:7002]&host[127.0.0.1:7003]&redis_cluster=1'
    : 'redis://127.0.0.1:6379';
$nodes = $mode === 'cluster' ? [7001, 7002, 7003] : [6379];
foreach ($nodes as $port) {
    $r = new Redis();
    $r->connect('127.0.0.1', $port);
    $r->flushAll();
    $r->config('RESETSTAT');
}

$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new StorageRedisDsnModule($dsn, [], 0, ['enabled' => true, 'type' => 'deflate']));
$injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');
$pool = fn (): TagAwareAdapterInterface => $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);

/** @return list<string> */
function pageTags(int $i): array
{
    mt_srand($i);
    $tags = [];
    while (count($tags) < 20) { $tags['s' . mt_rand(0, 199)] = true; }
    while (count($tags) < 100) { $tags['r' . mt_rand(0, 4999)] = true; }

    return array_keys($tags);
}

function commands(array $nodes): int
{
    $total = 0;
    foreach ($nodes as $port) {
        $r = new Redis();
        $r->connect('127.0.0.1', $port);
        foreach ($r->info('commandstats') as $stat) {
            preg_match('/calls=(\d+)/', $stat, $m);
            $total += (int) $m[1];
        }
    }

    return $total;
}

$p = $pool();
echo $mode, ' ', $p::class, ' on ', get_debug_type((fn () => $this->redis)->call($p)), "\n";

$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    $p->save($p->getItem('ro-page' . $i)->set($body)->tag(pageTags($i))->expiresAfter(31_536_000)) || exit("save failed $i\n");
}
printf("  保存 %.3fms\n", (hrtime(true) - $t) / 1e6 / 1000);

$c = commands($nodes);
$t = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    $pool()->getItem('ro-page' . $i)->isHit() || exit("miss $i\n");
}
printf("  読み %.3fms（コマンド %.1f 回）\n", (hrtime(true) - $t) / 1e6 / 1000, (commands($nodes) - $c - count($nodes)) / 1000);

// 行の tag 1 本を無効化し、その tag を持つ page だけが miss になるか
$target = 'r123';
$expected = array_values(array_filter(range(0, 999), static fn (int $i): bool => in_array($target, pageTags($i), true)));
$t = hrtime(true);
$pool()->invalidateTags([$target]);
$invalidateMs = (hrtime(true) - $t) / 1e6;
$missed = array_values(array_filter(range(0, 999), static fn (int $i): bool => ! $pool()->getItem('ro-page' . $i)->isHit()));
printf("  無効化 %s %.2fms: 期待した miss %s / 実際 %s\n", $target, $invalidateMs, json_encode($expected), json_encode($missed));

// 共有 tag 200 本
$t = hrtime(true);
$pool()->invalidateTags(array_map(static fn (int $i): string => 's' . $i, range(0, 199)));
$invalidateMs = (hrtime(true) - $t) / 1e6;
$hits = count(array_filter(range(0, 999), static fn (int $i): bool => $pool()->getItem('ro-page' . $i)->isHit()));
printf("  共有 tag 200 本の無効化 %.1fms: 残った hit %d\n", $invalidateMs, $hits);

// 保存し直して、ノードごとの量を見てから clear()
for ($i = 0; $i < 1000; $i++) {
    $p->save($p->getItem('ro-page' . $i)->set($body)->tag(pageTags($i))->expiresAfter(31_536_000));
}
$per = [];
foreach ($nodes as $port) {
    $r = new Redis();
    $r->connect('127.0.0.1', $port);
    $per[] = sprintf('%d: %d 鍵 %.1fMB', $port, $r->dbSize(), (int) $r->info('memory')['used_memory'] / 1048576);
}
echo '  ノード ', implode(' / ', $per), "\n";
$pool()->clear();
$left = 0;
foreach ($nodes as $port) {
    $r = new Redis();
    $r->connect('127.0.0.1', $port);
    $left += $r->dbSize();
}
echo "  clear() の後に残った鍵 $left\n";
