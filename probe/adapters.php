<?php

declare(strict_types=1);

// usage: php probe/adapters.php <rta|ta> <leftover|read|purge|lru|hot|garbage>

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

const PAGES = 1000;
const TAGS_PER_PAGE = 100;
const SHARED_TAGS = 200;   // 一覧・taxonomy など多くの page が共有する tag
const ROW_TAGS = 5000;     // 行の tag
const BODY_BYTES = 50_000;

/** @param 'rta'|'ta' $kind */
function adapter(string $kind, Redis $redis, string $namespace): TagAwareAdapterInterface
{
    return $kind === 'rta'
        ? new RedisTagAwareAdapter($redis, $namespace)
        : new TagAwareAdapter(new RedisAdapter($redis, $namespace));
}

/** @return list<string> */
function pageTags(int $page): array
{
    mt_srand($page);
    $tags = [];
    while (count($tags) < 20) {
        $tags['s' . mt_rand(0, SHARED_TAGS - 1)] = true;
    }

    while (count($tags) < TAGS_PER_PAGE) {
        $tags['r' . mt_rand(0, ROW_TAGS - 1)] = true;
    }

    return array_keys($tags);
}

/** @return array{keys:int, persistent:int, used_memory:int} */
function stats(Redis $redis): array
{
    $keys = 0;
    $persistent = 0;
    $it = null;
    while (($batch = $redis->scan($it, '*', 1000)) !== false) {
        foreach ($batch as $key) {
            $keys++;
            if ($redis->ttl($key) === -1) {
                $persistent++;
            }
        }
    }

    return ['keys' => $keys, 'persistent' => $persistent, 'used_memory' => (int) $redis->info('memory')['used_memory']];
}

function commands(Redis $redis): int
{
    $total = 0;
    foreach ($redis->info('commandstats') as $stat) {
        preg_match('/calls=(\d+)/', $stat, $m);
        $total += (int) $m[1];
    }

    return $total;
}

function savePages(TagAwareAdapterInterface $pool, int $lifetime, int $from = 0, int $count = PAGES): void
{
    $body = str_repeat('x', BODY_BYTES);
    for ($i = $from; $i < $from + $count; $i++) {
        $item = $pool->getItem('ro-page' . $i);
        $item->set($body)->tag(pageTags($i))->expiresAfter($lifetime === 0 ? null : $lifetime);
        $pool->save($item);
    }
}

function line(string $label, array $values): void
{
    echo str_pad($label, 34), json_encode($values), "\n";
}

$kind = $argv[1] ?? 'ta';
$scenario = $argv[2] ?? 'leftover';
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->config('RESETSTAT');

switch ($scenario) {
    case 'leftover':
        // namespace を 3 回替え、毎回 TTL 2 秒で全 page を保存し、期限切れを待つ
        $redis->flushAll();
        foreach (['ns1', 'ns2', 'ns3'] as $ns) {
            savePages(adapter($kind, $redis, $ns), 2);
            line("$kind saved $ns", stats($redis));
            sleep(3);
            line("$kind expired $ns", stats($redis));
        }

        break;

    case 'read':
        // 1 リクエスト = 新しい adapter。往復 (コマンド数) と時間
        $redis->flushAll();
        savePages(adapter($kind, $redis, 'ns'), 3600, 0, 1);
        $n = 1000;
        $before = commands($redis);
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $item = adapter($kind, $redis, 'ns')->getItem('ro-page0');
            assert($item->isHit());
        }

        $ms = (hrtime(true) - $t) / 1e6 / $n;
        line("$kind read", ['commands_per_read' => (commands($redis) - $before - 1) / $n, 'ms_per_read' => round($ms, 3)]);
        break;

    case 'purge':
        // 全 page を保存 → 共有 tag をすべて purge → 全 page を読む
        $redis->flushAll();
        savePages(adapter($kind, $redis, 'ns'), 3600);
        line("$kind saved", stats($redis));
        adapter($kind, $redis, 'ns')->invalidateTags(array_map(static fn (int $i): string => 's' . $i, range(0, SHARED_TAGS - 1)));
        line("$kind purged", stats($redis));
        $pool = adapter($kind, $redis, 'ns');
        $hits = 0;
        for ($i = 0; $i < PAGES; $i++) {
            $hits += (int) $pool->getItem('ro-page' . $i)->isHit();
        }

        line("$kind read all", stats($redis) + ['hits' => $hits]);
        break;

    case 'lru':
        // maxmemory 30MB の allkeys-lru で、purge 後に追い出しが起きても古い内容を返さないか
        $redis->flushAll();
        $redis->config('SET', 'maxmemory', '30mb');
        $redis->config('SET', 'maxmemory-policy', 'allkeys-lru');
        try {
            $pool = adapter($kind, $redis, 'ns');
            savePages($pool, 3600, 0, 300);
            $pool->invalidateTags(['s0']);
            savePages(adapter($kind, $redis, 'ns'), 3600, 300, (int) ($argv[3] ?? 2000));
            $pool = adapter($kind, $redis, 'ns');
            $stale = 0;
            $hits = 0;
            for ($i = 0; $i < 300; $i++) {
                $item = $pool->getItem('ro-page' . $i);
                $hits += (int) $item->isHit();
                $stale += (int) ($item->isHit() && in_array('s0', pageTags($i), true));
            }

            line("$kind lru", stats($redis) + ['evicted' => (int) $redis->info('stats')['evicted_keys'], 'hits_of_first_300' => $hits, 'stale' => $stale]);
        } catch (Throwable $e) {
            line("$kind lru", ['error' => get_class($e) . ': ' . $e->getMessage()]);
        } finally {
            $redis->config('SET', 'maxmemory', '0');
            $redis->config('SET', 'maxmemory-policy', 'noeviction');
        }

        break;
    case 'hot':
    case 'garbage':
        // allkeys-lru 30MB。50 件の hot page を読み続けながら、新しい page を書き続ける
        // garbage: 先に古い namespace に TTL なしで 400 page (約 20MB) を残し、新しい側の生きた page は 250 件にする
        $redis->flushAll();
        $redis->config('SET', 'maxmemory', '30mb');
        $redis->config('SET', 'maxmemory-policy', $kind === 'rta' ? 'volatile-lru' : 'allkeys-lru');
        if ($scenario === 'garbage') {
            savePages(adapter($kind, $redis, 'old'), 0, 0, 400);
        }

        savePages(adapter($kind, $redis, 'ns'), 3600, 0, 50);
        $hotHits = 0;
        $reads = 0;
        for ($b = 0; $b < ($scenario === 'garbage' ? 4 : 40); $b++) {
            savePages(adapter($kind, $redis, 'ns'), 3600, 50 + $b * 50, 50);
            $pool = adapter($kind, $redis, 'ns');
            for ($i = 0; $i < 50; $i++) {
                $reads++;
                $hit = $pool->getItem('ro-page' . $i)->isHit();
                $hotHits += (int) $hit;
                if (! $hit) {
                    savePages($pool, 3600, $i, 1);
                }
            }
        }

        $present = 0;
        $presentMiss = 0;
        $pool = adapter($kind, $redis, 'ns');
        for ($i = 0; $i < 2050; $i++) {
            if ($redis->exists('ns:ro-page' . $i)) {
                $present++;
                $presentMiss += (int) ! $pool->getItem('ro-page' . $i)->isHit();
            }
        }

        line("$kind $scenario", ['old_pages_left' => count($redis->keys('old:ro-page*')), 'evicted' => (int) $redis->info('stats')['evicted_keys'], 'hot_hit_rate' => round($hotHits / $reads, 3), 'pages_present' => $present, 'present_but_miss' => $presentMiss]);
        $redis->config('SET', 'maxmemory', '0');
        $redis->config('SET', 'maxmemory-policy', 'noeviction');
        break;
}
