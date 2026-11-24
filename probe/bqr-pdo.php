<?php

declare(strict_types=1);

// usage: php probe/bqr-pdo.php <redis|pdo>
// BQR の fake app の page を、pool を Redis（RedisTagAwareAdapter）か MySQL だけ（TagAwareAdapter + PdoAdapter 2 つ）にして GET し、
// hit のときの 1 リクエストあたりの時間と、Redis のコマンド数 / SQL の数を数える。どちらも deflate。
// TagAwareAdapter の tag の版の使い回し（既定 0.15 秒）は、リクエストをまたいで効かないよう 0 にした（本番の 1 リクエスト 1 プロセスに近づける）。
use BEAR\QueryRepository\MarshallerProvider;
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

final class PoolModule extends AbstractModule
{
    public function __construct(private TagAwareAdapterInterface $pool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($this->pool);
    }
}

$kind = $argv[1] ?? 'pdo';
$deflate = ['enabled' => true, 'type' => 'deflate'];
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$pdo = null;
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$redis->flushAll();
if ($kind === 'pdo') {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3407;dbname=cache', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (['cache_items', 'cache_tags'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS $table");
        (new PdoAdapter($pdo, '', 0, ['db_table' => $table]))->createTable();
    }
    $marshaller = (new MarshallerProvider($deflate))->get();
    $module->override(new PoolModule(new TagAwareAdapter(
        new PdoAdapter($pdo, 'ns', 0, ['db_table' => 'cache_items'], $marshaller),
        new PdoAdapter($pdo, 'ns-tags', 0, ['db_table' => 'cache_tags']),
        0,
    )));
} else {
    $module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379', [], 0, $deflate));
}

$resource = (new Injector($module, dirname(__DIR__) . '/tests/tmp'))->getInstance(ResourceInterface::class);

$count = static function () use ($kind, $pdo, $redis): int {
    if ($kind === 'pdo') {
        return (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM)[1];
    }
    $total = 0;
    foreach ($redis->info('commandstats') as $name => $stat) {
        if ($name !== 'cmdstat_info') {
            preg_match('/calls=(\d+)/', $stat, $m);
            $total += (int) $m[1];
        }
    }

    return $total;
};

$pages = [
    'app://self/user?id=1' => '#[Cacheable] の app resource',
    'page://self/html/blog-posting' => '#[CacheableResponse] の page（穴 1 つ）',
    'page://self/html/blog-posting-donut' => '#[DonutCache] の page（キャッシュする穴 1 つ）',
    'page://self/html/counter-donut' => '#[DonutCache] の page（キャッシュしない穴 1 つ）',
];
$n = 200;
foreach ($pages as $uri => $label) {
    $first = $resource->get($uri);
    $render = str_starts_with($uri, 'page://'); // app resource には html のテンプレートがない
    $render && (string) $first; // 最初の GET（miss）で保存させる
    $c = $count();
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $ro = $resource->get($uri);
        $view = $render ? (string) $ro : null;
    }
    $ms = (hrtime(true) - $t) / 1e6 / $n;
    $per = ($count() - $c - ($kind === 'pdo' ? 1 : 0)) / $n;
    printf("%-5s %-45s %.3fms  %s %.1f 回  Age=%s\n", $kind, $label, $ms, $kind === 'pdo' ? 'SQL' : 'Redis', $per, $ro->headers['Age'] ?? '-');
}

// 1 リクエストごとに新しく接続する場合の、接続そのものにかかる時間
$t = hrtime(true);
for ($i = 0; $i < 200; $i++) {
    if ($kind === 'pdo') {
        (new PDO('mysql:host=127.0.0.1;port=3407;dbname=cache', 'root', ''))->query('SELECT 1');
    } else {
        $r = new Redis();
        $r->connect('127.0.0.1', 6379);
        $r->ping();
        $r->close();
    }
}
printf("%-5s 接続 1 回（接続して 1 往復）                     %.3fms\n", $kind, (hrtime(true) - $t) / 1e6 / 200);
