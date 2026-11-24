<?php

declare(strict_types=1);

// 小さな値で deflate が効くかを見る。(1) 文字列の長さごとの、DefaultMarshaller と DeflateMarshaller の大きさ。
// (2) BQR が実際に保存する小さな #[Cacheable] の値の、Redis 上の長さ（STRLEN）を圧縮なしと圧縮ありで比べる。
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\Resource\ResourceInterface;
use Ray\Di\Injector;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

echo "(1) 文字列 1 つの値\n";
$plain = new DefaultMarshaller();
$deflate = new DeflateMarshaller(new DefaultMarshaller());
foreach ([1, 10, 20, 50, 100, 200, 500] as $len) {
    $value = substr(str_repeat('{"id":1,"name":"bear","age":"3"}', 20), 0, $len);
    $a = strlen($plain->marshall(['k' => $value], $failed)['k']);
    $b = strlen($deflate->marshall(['k' => $value], $failed)['k']);
    printf("  %4d 文字: 圧縮なし %4d / 圧縮あり %4d バイト（%+d）\n", $len, $a, $b, $b - $a);
}

echo "(2) BQR が保存する小さな値（STRLEN）\n";
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$uris = ['app://self/user?id=1', 'app://self/control-none', 'app://self/control-public', 'app://self/etag', 'app://self/null-view'];
$sizes = [];
foreach (['圧縮なし' => [], '圧縮あり' => ['enabled' => true, 'type' => 'deflate']] as $label => $options) {
    $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
    $module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379', [], 0, $options));
    $resource = (new Injector($module, dirname(__DIR__) . '/tests/tmp'))->getInstance(ResourceInterface::class);
    foreach ($uris as $uri) {
        $redis->flushAll();
        try {
            $resource->get($uri);
        } catch (Throwable $e) {
            $sizes[$uri][$label] = get_class($e);
            continue;
        }

        $total = 0;
        $it = null;
        while (($keys = $redis->scan($it, 'ro-*', 100)) !== false) {
            foreach ($keys as $key) {
                $total += $redis->strlen($key);
            }
        }
        $sizes[$uri][$label] = $total;
    }
}
foreach ($sizes as $uri => $row) {
    printf("  %-28s 圧縮なし %5s / 圧縮あり %5s バイト\n", $uri, $row['圧縮なし'], $row['圧縮あり']);
}
