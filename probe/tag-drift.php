<?php

declare(strict_types=1);

// usage: php probe/tag-drift.php <invalidate|overwrite>
// 1 つの namespace の中で、page の tag が入れ替わり続けるとき（新しい行が増え、一覧が新しい行を載せる）に、tag の SET が溜まるかを見る。
// 一覧 page 100 件。各 page は共有 tag 'list' と、新しい 1000 行の中からランダムな 50 行の tag を持つ。
// 1 回ごとに 200 行が増え、'list' を無効化して（一覧への書き込み）、全 page を保存し直す。古い行への書き込みはない。
use BEAR\QueryRepository\MarshallerProvider;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushAll();
$pool = new RedisTagAwareAdapter(RedisAdapter::createConnection('redis://127.0.0.1:6379'), 'ns', 0, (new MarshallerProvider(['enabled' => true, 'type' => 'deflate']))->get());
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);

function counts(Redis $redis): string
{
    $items = $tags = $tagBytes = $members = 0;
    $it = null;
    while (($keys = $redis->scan($it, '*', 1000)) !== false) {
        foreach ($keys as $key) {
            if ($redis->ttl($key) === -1) {
                $tags++;
                $members += $redis->sCard($key);
                $tagBytes += (int) $redis->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', '0');
            } else {
                $items++;
            }
        }
    }

    return sprintf('本体 %d 本 / tag の SET %d 本（要素 %d、%.2fMB） / used_memory %.1fMB', $items, $tags, $members, $tagBytes / 1048576, (int) $redis->info('memory')['used_memory'] / 1048576);
}

// invalidate: 一覧への書き込みで 'list' を無効化してから保存し直す（miss からの保存）
// overwrite:  無効化せず、生きている項目をそのまま保存し直す（hit からの保存）
$mode = $argv[1] ?? 'invalidate';
echo "== $mode\n";
$latest = 1000;
for ($round = 1; $round <= 50; $round++) {
    $latest += 200;
    if ($mode === 'invalidate') {
        $pool->invalidateTags(['list']);
    }
    for ($p = 0; $p < 100; $p++) {
        $rows = [];
        while (count($rows) < 50) {
            $rows['row' . mt_rand($latest - 999, $latest)] = true;
        }
        $pool->save($pool->getItem('ro-list' . $p)->set($body)->tag(['list', ...array_keys($rows)])->expiresAfter(31_536_000));
    }
    if (in_array($round, [1, 10, 25, 50], true)) {
        printf("%2d 回目（行 %5d）: %s\n", $round, $latest, counts($redis));
    }
}

// 今の page はどれも新しい 1000 行からしか tag を取らないので、それより古い行の SET の要素は、どの page の今の tag でもない
$staleSets = $staleMembers = 0;
$sample = null;
$it = null;
while (($keys = $redis->scan($it, '*row*', 1000)) !== false) {
    foreach ($keys as $key) {
        if (preg_match('/row(\d+)$/', $key, $m) === 1 && (int) $m[1] < $latest - 999) {
            $staleSets++;
            $members = $redis->sMembers($key);
            $staleMembers += count($members);
            $sample ??= [$key, $members];
        }
    }
}
printf("一覧から外れた行の SET %d 本、その要素 %d", $staleSets, $staleMembers);
if ($sample !== null) {
    [$key, $members] = $sample;
    printf("。例 %s の要素 %s（項目は今もある: %s）", json_encode($key), json_encode(array_slice($members, 0, 3)), json_encode(array_map(static fn (string $id): bool => (bool) $redis->exists($id), array_slice($members, 0, 3))));
}
echo "\n";
