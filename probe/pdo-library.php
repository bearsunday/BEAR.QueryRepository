<?php

declare(strict_types=1);

// BQR の既存の束縛の言葉（AdapterInterface #[ResourceObjectPool]、TagsPool は null で同じ pool）だけで、PdoAdapter（SQLite）を pool にできるかと、
// その pool で (1) page の hit、(2) tag が移る一覧での tag の行の増え方、(3) clear() が namespace ごとに消えるか、を確かめる。
use BEAR\QueryRepository\ModuleFactory;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\PdoAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require dirname(__DIR__) . '/vendor/autoload.php';

$file = __DIR__ . '/pdo-library.sqlite';
@unlink($file);
$pdo = new PDO('sqlite:' . $file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
(new PdoAdapter($pdo))->createTable();
$rows = static fn (): string => json_encode($pdo->query("SELECT substr(item_id, 1, instr(item_id, ':') - 1) ns, CASE WHEN instr(item_id, char(1)) > 0 OR instr(item_id, 'tags') > 0 THEN 'tag' ELSE 'item' END kind, COUNT(*) n FROM cache_items GROUP BY ns, kind ORDER BY ns, kind")->fetchAll(PDO::FETCH_NUM));

final class PdoPoolModule extends AbstractModule
{
    public function __construct(private PdoAdapter $adapter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($this->adapter);
    }
}

// (1) BQR の page が、この束縛だけで hit するか
$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$module->override(new PdoPoolModule(new PdoAdapter($pdo, 'app')));
$injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');
$pool = $injector->getInstance(TagAwareAdapterInterface::class, ResourceObjectPool::class);
$resource = $injector->getInstance(ResourceInterface::class);
echo '(1) pool: ', $pool::class, "\n";
foreach (['page://self/html/blog-posting', 'page://self/html/counter-donut'] as $uri) {
    $miss = (string) $resource->get($uri);
    $hit = $resource->get($uri);
    $view = (string) $hit;
    printf("    %s: 2 回目 Age=%s、view が同じ=%s\n", $uri, $hit->headers['Age'] ?? '-', json_encode($miss === $view));
}
echo '    行: ', $rows(), "\n";

// (2) tag が移る一覧（tag-drift.php と同じ流れ）。TagAwareAdapter は tag ごとに版の行を 1 つ持つ
$drift = new TagAwareAdapter(new PdoAdapter($pdo, 'drift'), null, 0);
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);
$latest = 1000;
for ($round = 1; $round <= 50; $round++) {
    $latest += 200;
    $drift->invalidateTags(['list']);
    for ($p = 0; $p < 100; $p++) {
        $tags = [];
        while (count($tags) < 50) {
            $tags['row' . mt_rand($latest - 999, $latest)] = true;
        }
        $drift->save($drift->getItem('ro-list' . $p)->set($body)->tag(['list', ...array_keys($tags)]));
    }
    if (in_array($round, [1, 10, 25, 50], true)) {
        $tagRows = $pdo->query("SELECT COUNT(*), COALESCE(SUM(LENGTH(item_data)), 0) FROM cache_items WHERE item_id LIKE 'drift:%' AND item_id NOT LIKE 'drift:ro-%'")->fetch(PDO::FETCH_NUM);
        $itemRows = $pdo->query("SELECT COUNT(*) FROM cache_items WHERE item_id LIKE 'drift:ro-%'")->fetchColumn();
        printf("(2) %2d 回目（行 %5d）: 本体 %d 行 / tag の版 %d 行（item_data 合計 %d バイト）\n", $round, $latest, $itemRows, $tagRows[0], $tagRows[1]);
    }
}

// (3) clear() は namespace ごとか
echo '(3) clear() の前: ', $rows(), "\n";
(new TagAwareAdapter(new PdoAdapter($pdo, 'drift')))->clear();
echo '    drift に clear() の後: ', $rows(), "\n";
@unlink($file);
