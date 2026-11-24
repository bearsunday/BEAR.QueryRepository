<?php

declare(strict_types=1);

// RedisTagAwareAdapter に 1000 page（実在の HTML 50KB、tag 100 本）を保存したときの used_memory
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
$body = substr(str_repeat((string) file_get_contents(__DIR__ . '/sample.html'), 2), 0, 50_000);
foreach (['default' => new DefaultMarshaller(), 'deflate' => new DeflateMarshaller(new DefaultMarshaller())] as $name => $marshaller) {
    $redis->flushAll();
    $base = (int) $redis->info('memory')['used_memory'];
    $pool = new RedisTagAwareAdapter($redis, 'ns', 0, $marshaller);
    $t = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        mt_srand($i);
        $tags = [];
        while (count($tags) < 20) { $tags['s' . mt_rand(0, 199)] = true; }
        while (count($tags) < 100) { $tags['r' . mt_rand(0, 4999)] = true; }
        $pool->save($pool->getItem('ro-page' . $i)->set($body)->tag(array_keys($tags))->expiresAfter(31_536_000));
    }
    $saveMs = (hrtime(true) - $t) / 1e6 / 1000;
    $t = hrtime(true);
    for ($i = 0; $i < 1000; $i++) {
        (new RedisTagAwareAdapter($redis, 'ns', 0, $marshaller))->getItem('ro-page' . $i)->isHit() || exit("miss\n");
    }
    printf("%-8s used_memory=%.1fMB save=%.3fms read=%.3fms\n", $name, ((int) $redis->info('memory')['used_memory'] - $base) / 1048576, $saveMs, (hrtime(true) - $t) / 1e6 / 1000);
}
