<?php

declare(strict_types=1);

// BQR が page 1 つのために Redis に置くもの（テンプレート、組み上がった view、穴の値、tag のメモ）と、その量を出す。
// page は約 50KB の実在の HTML に、#[Cacheable] の穴（comment）を 1 つ持つ。#[CacheableResponse] と #[DonutCache] を比べる。
// 2 回目の GET（hit）の応答ヘッダーも出す。
use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$cdnHeaders = ['Surrogate-Key', 'CDN-Cache-Control', 'ETag', 'Last-Modified', 'Cache-Control', 'Age'];
foreach (['default' => [], 'deflate' => ['enabled' => true, 'type' => 'deflate']] as $marshal => $options) {
    foreach (['page://self/html/probe-large-page' => '#[CacheableResponse]', 'page://self/html/probe-large-donut' => '#[DonutCache]'] as $uri => $label) {
        $redis->flushAll();
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379', [], 0, $options));
        $resource = (new Injector($module, dirname(__DIR__) . '/tests/tmp'))->getInstance(ResourceInterface::class);
        $view = (string) $resource->get($uri);

        $groups = [];
        $it = null;
        while (($keys = $redis->scan($it, '*', 100)) !== false) {
            foreach ($keys as $key) {
                $ttl = $redis->ttl($key);
                $kind = match (true) {
                    $ttl === -1 => 'tag のメモ',
                    str_starts_with($key, 'donut-') => 'テンプレート（donut-）',
                    $ttl === 8_640_000 => '組み上がった view（ro-、100 日）',
                    default => '穴の値（ro-、1 年）',
                };
                $groups[$kind][0] = ($groups[$kind][0] ?? 0) + 1;
                $groups[$kind][1] = ($groups[$kind][1] ?? 0) + (int) $redis->rawCommand('MEMORY', 'USAGE', $key, 'SAMPLES', '0');
            }
        }

        ksort($groups);
        printf("%-8s %-22s 応答 %d バイト\n", $marshal, $label, strlen($view));
        foreach ($groups as $kind => [$n, $bytes]) {
            printf("    %-34s %3d 本 %8d バイト\n", $kind, $n, $bytes);
        }

        $hit = $resource->get($uri);
        (string) $hit;
        $shown = [];
        foreach ($cdnHeaders as $name) {
            if (isset($hit->headers[$name])) {
                $shown[] = $name === 'Surrogate-Key' ? "$name(" . count(explode(' ', $hit->headers[$name])) . ' 本)' : "$name: {$hit->headers[$name]}";
            }
        }
        echo '    2 回目のヘッダー: ', implode(' / ', $shown), "\n";
    }
}
