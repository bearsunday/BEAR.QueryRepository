<?php

declare(strict_types=1);

// usage: php probe/throughput.php <single|cluster> <read|save> <workers> [seconds]
// N 個のプロセスが同時に読み（または保存）を続け、全体の回数/秒と、Redis の各ノードが使った CPU を測る。
// 各プロセスは接続を 1 本作って使い回す（PHP-FPM の 1 リクエストの中と同じ）。page はあらかじめ 1000 件（deflate）入れておく。
use BEAR\QueryRepository\MarshallerProvider;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

[$mode, $op, $workers, $seconds] = [$argv[1] ?? 'single', $argv[2] ?? 'read', (int) ($argv[3] ?? 1), (float) ($argv[4] ?? 3)];
$dsn = $mode === 'cluster'
    ? 'redis:?host[127.0.0.1:7001]&host[127.0.0.1:7002]&host[127.0.0.1:7003]&redis_cluster=1'
    : 'redis://127.0.0.1:6379';
$ports = $mode === 'cluster' ? [7001, 7002, 7003] : [6379];
$marshaller = (new MarshallerProvider(['enabled' => true, 'type' => 'deflate']))->get();
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);

function tags(int $i): array
{
    mt_srand($i);
    $tags = [];
    while (count($tags) < 20) { $tags['s' . mt_rand(0, 199)] = true; }
    while (count($tags) < 100) { $tags['r' . mt_rand(0, 4999)] = true; }

    return array_keys($tags);
}

function cpu(array $ports): array
{
    $out = [];
    foreach ($ports as $port) {
        $r = new Redis();
        $r->connect('127.0.0.1', $port);
        $info = $r->info('cpu');
        $out[$port] = (float) $info['used_cpu_user'] + (float) $info['used_cpu_sys'];
    }

    return $out;
}

$pool = new RedisTagAwareAdapter(RedisAdapter::createConnection($dsn), 'ns', 0, $marshaller);
if ($op === 'read' && ! $pool->getItem('ro-page999')->isHit()) {
    for ($i = 0; $i < 1000; $i++) {
        $pool->save($pool->getItem('ro-page' . $i)->set($body)->tag(tags($i))->expiresAfter(31_536_000));
    }
}

$start = microtime(true) + 0.5;
$end = $start + $seconds;
$pipes = [];
for ($w = 0; $w < $workers; $w++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($pair[0]);
        $p = new RedisTagAwareAdapter(RedisAdapter::createConnection($dsn), 'ns', 0, $marshaller);
        mt_srand(getmypid());
        $tagsOf = array_map(tags(...), range(0, 99)); // 保存は tag の生成を計測に入れない
        time_sleep_until($start);
        $n = 0;
        while (microtime(true) < $end) {
            $i = mt_rand(0, 999);
            if ($op === 'read') {
                $p->getItem('ro-page' . $i)->isHit() || exit(1);
            } else {
                $p->save($p->getItem('ro-page' . $i)->set($body)->tag($tagsOf[$i % 100])->expiresAfter(31_536_000));
            }
            $n++;
        }
        fwrite($pair[1], (string) $n);
        exit(0);
    }
    fclose($pair[1]);
    $pipes[] = $pair[0];
}

time_sleep_until($start);
$before = cpu($ports);
$total = 0;
foreach ($pipes as $pipe) {
    $total += (int) stream_get_contents($pipe);
}
while (pcntl_wait($status) > 0);
$after = cpu($ports);
$usage = array_map(static fn (int $port): string => sprintf('%d:%3.0f%%', $port, ($after[$port] - $before[$port]) / $seconds * 100), $ports);
printf("%-7s %-4s workers=%2d  %8.0f 回/秒  Redis CPU %s\n", $mode, $op, $workers, $total / $seconds, implode(' ', $usage));
