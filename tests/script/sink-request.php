<?php

/**
 * One request that ends without flushing, the way a real one does
 *
 * The point of the sink is that nothing in the app calls flush(): the session has to reach the
 * writer through the end of the request. This script performs a GET and stops - optionally by
 * exiting, the way a 304 answer does in a bootstrap - so a test can prove the log was written
 * anyway.
 *
 * Usage: php sink-request.php <logDir> [--exit]
 */

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use Ray\Di\Injector;

use function dirname;
use function in_array;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logDir = (string) ($argv[1] ?? '');
$inner = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
$injector = new Injector(new DevQueryRepositoryLogModule($logDir, module: $inner), dirname(__DIR__) . '/tmp');
$injector->getInstance(ResourceInterface::class)->get('page://self/html/blog-posting');

if (in_array('--exit', $argv, true)) {
    exit(0); // the 304 path: the bootstrap stops here
}
