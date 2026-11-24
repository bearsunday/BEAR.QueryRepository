<?php

declare(strict_types=1);

// default と deflate で、page 本体と tag の SET の量を分けて測る。namespace を替えた後（デプロイ）と、古い namespace を clear() した後も見る。
// adapter は StorageRedisDsnModule の toConstructor と同じ引数で組む（marshaller は MarshallerProvider から）。
use BEAR\QueryRepository\MarshallerProvider;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);

function pool(Redis $redis, string $ns, array $options): RedisTagAwareAdapter
{
    return new RedisTagAwareAdapter($redis, $ns, 0, (new MarshallerProvider($options))->get());
}

function savePages(RedisTagAwareAdapter $pool, string $body): float
{
    $t = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        mt_srand($i);
        $tags = [];
        while (count($tags) < 20) { $tags['s' . mt_rand(0, 199)] = true; }
        while (count($tags) < 100) { $tags['r' . mt_rand(0, 4999)] = true; }
        $pool->save($pool->getItem('ro-page' . $i)->set($body)->tag(array_keys($tags))->expiresAfter(31_536_000)) || exit("save failed\n");
    }

    return (hrtime(true) - $t) / 1e6 / 1000;
}

/** namespace ごとに、TTL のある鍵（page 本体）と TTL のない鍵（tag の SET）の数と MEMORY USAGE の合計 */
function breakdown(Redis $redis): string
{
    $groups = [];
    $it = null;
    while (($batch = $redis->scan($it, '*', 1000)) !== false) {
        foreach ($batch as $key) {
            $group = explode(':', $key, 2)[0] . ($redis->ttl($key) === -1 ? ' tag(TTLなし)' : ' 本体');
            $groups[$group][0] = ($groups[$group][0] ?? 0) + 1;
            $groups[$group][1] = ($groups[$group][1] ?? 0) + (int) $redis->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', '0');
        }
    }

    ksort($groups);
    $out = [];
    foreach ($groups as $group => [$n, $bytes]) {
        $out[] = sprintf('%s %d本 %.1fMB', $group, $n, $bytes / 1048576);
    }

    return implode(' / ', $out) . sprintf('  (used_memory %.1fMB)', (int) $redis->info('memory')['used_memory'] / 1048576);
}

foreach (['default' => [], 'deflate' => ['enabled' => true, 'type' => 'deflate']] as $name => $options) {
    $redis->flushAll();
    echo "== $name\n";
    $saveMs = savePages(pool($redis, 'ns1', $options), $body);
    $t = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        pool($redis, 'ns1', $options)->getItem('ro-page' . $i)->isHit() || exit("miss\n");
    }

    printf("  保存 %.3fms / 読み %.3fms\n", $saveMs, (hrtime(true) - $t) / 1e6 / 1000);
    echo '  ns1 保存後      ', breakdown($redis), "\n";
    savePages(pool($redis, 'ns2', $options), $body);
    echo '  ns2 に替えた後  ', breakdown($redis), "\n";
    pool($redis, 'ns1', $options)->clear();
    echo '  ns1 を clear()  ', breakdown($redis), "\n";
}
