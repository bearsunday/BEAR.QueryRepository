<?php

declare(strict_types=1);

// usage: php probe/pdo.php <sqlite|mysql> <pdo|redis> <default|deflate>
//   第 2 引数は tag の版を置く場所。page の本体はいつも PdoAdapter。

use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

const PAGES = 1000;
const TAGS_PER_PAGE = 100;
const SHARED_TAGS = 200;
const ROW_TAGS = 5000;
const BODY_BYTES = 50_000;

[$backend, $tagsAt, $marshal] = [$argv[1] ?? 'sqlite', $argv[2] ?? 'pdo', $argv[3] ?? 'default'];

$pdo = $backend === 'sqlite'
    ? new PDO('sqlite:' . __DIR__ . '/pdo.sqlite')
    : new PDO('mysql:host=127.0.0.1;port=3407;dbname=cache', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$redis = null;
if ($tagsAt === 'redis') {
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);
    $redis->flushAll();
}

foreach (['cache_items', 'cache_tags'] as $table) {
    $pdo->exec("DROP TABLE IF EXISTS $table");
}

$marshaller = $marshal === 'deflate' ? new DeflateMarshaller(new DefaultMarshaller()) : new DefaultMarshaller();
(new PdoAdapter($pdo, '', 0, ['db_table' => 'cache_items']))->createTable();
(new PdoAdapter($pdo, '', 0, ['db_table' => 'cache_tags']))->createTable();

function pool(PDO $pdo, Redis|null $redis, MarshallerInterface $marshaller, string $ns): TagAwareAdapter
{
    $items = new PdoAdapter($pdo, $ns, 0, ['db_table' => 'cache_items'], $marshaller);
    $tags = $redis instanceof Redis ? new RedisAdapter($redis, $ns . '-tags') : new PdoAdapter($pdo, $ns . '-tags', 0, ['db_table' => 'cache_tags']);

    return new TagAwareAdapter($items, $tags);
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

function body(): string
{
    static $body = null;

    return $body ??= substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, BODY_BYTES);
}

/** @return float ms per page */
function savePages(TagAwareAdapter $pool, int|null $lifetime): float
{
    $t = hrtime(true);
    for ($i = 0; $i < PAGES; $i++) {
        $pool->save($pool->getItem('ro-page' . $i)->set(body())->tag(pageTags($i))->expiresAfter($lifetime));
    }

    return round((hrtime(true) - $t) / 1e6 / PAGES, 3);
}

function rows(PDO $pdo, string $table): array
{
    return $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(LENGTH(item_data)), 0) bytes FROM $table")->fetch(PDO::FETCH_ASSOC);
}

function questions(PDO $pdo, string $backend): int
{
    return $backend === 'mysql' ? (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1] : 0;
}

function line(string $label, array $values): void
{
    echo str_pad($label, 22), json_encode($values), "\n";
}

$label = "$backend/$tagsAt/$marshal";
echo "== $label\n";

$ms = savePages(pool($pdo, $redis, $marshaller, 'ns1'), 31_536_000);
line('save', ['ms_per_page' => $ms, 'items' => rows($pdo, 'cache_items'), 'tag_rows' => rows($pdo, 'cache_tags')['n'], 'redis_keys' => $redis?->dbSize()]);

// 1 リクエスト = 新しい adapter で 1 page を読む
$n = 1000;
$q = questions($pdo, $backend);
$t = hrtime(true);
for ($i = 0; $i < $n; $i++) {
    $hit = pool($pdo, $redis, $marshaller, 'ns1')->getItem('ro-page' . ($i % PAGES))->isHit();
    assert($hit);
}

line('read', ['ms_per_read' => round((hrtime(true) - $t) / 1e6 / $n, 3), 'sql_per_read' => $backend === 'mysql' ? round((questions($pdo, $backend) - $q - 1) / $n, 2) : null]);

$t = hrtime(true);
pool($pdo, $redis, $marshaller, 'ns1')->invalidateTags(array_map(static fn (int $i): string => 's' . $i, range(0, SHARED_TAGS - 1)));
$purgeMs = round((hrtime(true) - $t) / 1e6, 1);
$hits = 0;
$pool = pool($pdo, $redis, $marshaller, 'ns1');
for ($i = 0; $i < PAGES; $i++) {
    $hits += (int) $pool->getItem('ro-page' . $i)->isHit();
}

line('purge shared 200', ['ms' => $purgeMs, 'items_left' => rows($pdo, 'cache_items')['n'], 'hits_after' => $hits]);

savePages(pool($pdo, $redis, $marshaller, 'ns1'), 31_536_000);
line('resave', ['items' => rows($pdo, 'cache_items')['n']]);

savePages(pool($pdo, $redis, $marshaller, 'ns2'), 31_536_000);
$prunable = new PdoAdapter($pdo, 'ns2', 0, ['db_table' => 'cache_items']);
$prunable->prune();
line('namespace ns2 + prune', ['items' => rows($pdo, 'cache_items')]);
