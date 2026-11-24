<?php

declare(strict_types=1);

// tag のメモ（Redis の SET）1 本の要素 1 つあたりの量を、要素の数ごとに出す。要素は BQR の項目の id の形（"ns:ro-" + 22 文字）。
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushAll();
foreach ([16, 100, 128, 129, 1000, 10000, 100000] as $n) {
    $key = "ns:\x01tags\x01t$n";
    $members = [];
    for ($i = 0; $i < $n; $i++) {
        $members[] = 'ns:ro-' . substr(strtr(base64_encode(md5((string) $i, true)), '+/', '-_'), 0, 22);
    }

    foreach (array_chunk($members, 1000) as $chunk) {
        $redis->sAdd($key, ...$chunk);
    }

    $bytes = (int) $redis->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', '0');
    printf("要素 %6d  encoding %-9s  %9d バイト  要素 1 つ %5.1f バイト\n", $n, $redis->object('encoding', $key), $bytes, $bytes / $n);
}
