<?php

declare(strict_types=1);

// usage: php probe/clear-impact.php <old pages> <with-old-server|no-old-server>
// 大きな古い namespace に clear() を当てたときの、(1) かかる時間、(2) 今の namespace の読みの遅れ、
// (3) 古い namespace を読み書きし続けるプロセス（古い版のサーバー）がいたときの結果を測る。
use BEAR\QueryRepository\MarshallerProvider;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$oldPages = (int) ($argv[1] ?? 30000);
$withOldServer = ($argv[2] ?? 'with-old-server') === 'with-old-server';
$marshaller = (new MarshallerProvider(['enabled' => true, 'type' => 'deflate']))->get();
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);
$stopFile = __DIR__ . '/clear-impact.stop';
$windowFile = __DIR__ . '/clear-impact.window';
@unlink($stopFile);
@unlink($windowFile);

function pool(string $ns, $marshaller): RedisTagAwareAdapter
{
    return new RedisTagAwareAdapter(RedisAdapter::createConnection('redis://127.0.0.1:6379'), $ns, 0, $marshaller);
}

function tags(int $i): array
{
    mt_srand($i);
    $tags = [];
    while (count($tags) < 20) { $tags['s' . mt_rand(0, 199)] = true; }
    while (count($tags) < 100) { $tags['r' . mt_rand(0, 4999)] = true; }

    return array_keys($tags);
}

/** @param callable(int): void $work */
function forkAll(int $workers, callable $work): void
{
    for ($w = 0; $w < $workers; $w++) {
        if (pcntl_fork() === 0) {
            $work($w);
            exit(0);
        }
    }
    while (pcntl_wait($status) > 0);
}

$admin = new Redis();
$admin->connect('127.0.0.1', 6379);
$admin->flushAll();

$t = hrtime(true);
forkAll(8, static function (int $w) use ($oldPages, $marshaller, $body): void {
    $old = pool('old', $marshaller);
    for ($i = $w; $i < $oldPages; $i += 8) {
        $old->save($old->getItem('ro-page' . $i)->set($body)->tag(tags($i))->expiresAfter(31_536_000));
    }
    if ($w === 0) {
        $cur = pool('cur', $marshaller);
        for ($i = 0; $i < 1000; $i++) {
            $cur->save($cur->getItem('ro-page' . $i)->set($body)->tag(tags($i))->expiresAfter(31_536_000));
        }
    }
});
printf("積んだ: old %d page + cur 1000 page、鍵 %d 本、used_memory %.0fMB（%.0f 秒）\n", $oldPages, $admin->dbSize(), (int) $admin->info('memory')['used_memory'] / 1048576, (hrtime(true) - $t) / 1e9);

$children = [];
// 今の namespace を読み続ける。1 回ごとの時刻と所要時間を記録し、最後に clear() の前と最中に分けて集計する
$children[] = [static function ($out) use ($marshaller, $stopFile, $windowFile): void {
    $cur = pool('cur', $marshaller);
    $samples = [];
    while (! file_exists($stopFile)) {
        $s = hrtime(true);
        $cur->getItem('ro-page' . mt_rand(0, 999))->isHit() || exit(1);
        $samples[] = [$s, hrtime(true) - $s];
    }
    [$from, $to] = array_map('intval', explode(' ', (string) file_get_contents($windowFile)));
    $stat = static function (array $ms): string {
        sort($ms);
        $n = count($ms);

        return sprintf('%d 回 p50 %.3fms p99 %.3fms max %.1fms', $n, $ms[intdiv($n, 2)] / 1e6, $ms[(int) ($n * 0.99)] / 1e6, end($ms) / 1e6);
    };
    $before = array_column(array_filter($samples, static fn ($x) => $x[0] < $from), 1);
    $during = array_column(array_filter($samples, static fn ($x) => $x[0] >= $from && $x[0] <= $to), 1);
    fwrite($out, '今の namespace の読み  clear() の前: ' . $stat($before) . "\n                       clear() の最中: " . $stat($during) . "\n");
}];
if ($withOldServer) {
    // 古い版のサーバー: 古い namespace を読み、miss なら保存し直す
    $children[] = [static function ($out) use ($marshaller, $body, $oldPages, $stopFile): void {
        $old = pool('old', $marshaller);
        $hits = $misses = $errors = 0;
        while (! file_exists($stopFile)) {
            $i = mt_rand(0, $oldPages - 1);
            try {
                if ($old->getItem('ro-page' . $i)->isHit()) {
                    $hits++;
                } else {
                    $misses++;
                    $old->save($old->getItem('ro-page' . $i)->set($body)->tag(tags($i))->expiresAfter(31_536_000));
                }
            } catch (Throwable $e) {
                $errors++;
            }
        }
        fwrite($out, sprintf("古い版のサーバー        hit %d / miss して保存し直した %d / 例外 %d\n", $hits, $misses, $errors));
    }];
}

$pipes = [];
foreach ($children as [$work]) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if (pcntl_fork() === 0) {
        fclose($pair[0]);
        $work($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    $pipes[] = $pair[0];
}

sleep(2);
$from = hrtime(true);
pool('old', $marshaller)->clear();
$to = hrtime(true);
file_put_contents($windowFile, "$from $to");
sleep(2);
touch($stopFile);
foreach ($pipes as $pipe) {
    echo stream_get_contents($pipe);
}
while (pcntl_wait($status) > 0);

$left = 0;
$it = null;
while (($keys = $admin->scan($it, 'old:*', 1000)) !== false) {
    $left += count($keys);
}
printf("clear() %.2f 秒。終わって 2 秒後に残っていた old の鍵 %d 本、used_memory %.0fMB\n", ($to - $from) / 1e9, $left, (int) $admin->info('memory')['used_memory'] / 1048576);
@unlink($stopFile);
@unlink($windowFile);
