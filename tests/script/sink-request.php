<?php

/**
 * One request that ends without flushing, the way a real one does
 *
 * The point of the sink is that nothing in the app calls flush(): the session has to reach the
 * writer through the end of the request. This script performs a GET and stops - optionally by
 * exiting, the way a 304 answer does in a bootstrap - so a test can prove the log was written
 * anyway.
 *
 * Usage: php sink-request.php <logDir> [--exit] [--throw]
 *   --exit   stop with exit(0) after the GET, as a 304 bootstrap does
 *   --throw  bind a destination that throws, to prove a failing sink does not change the exit code
 */

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\LogWriterInterface;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\LogJson;
use Override;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use RuntimeException;

use function dirname;
use function in_array;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logDir = (string) ($argv[1] ?? '');
$inner = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
$module = new DevQueryRepositoryLogModule($logDir, module: $inner);
if (in_array('--throw', $argv, true)) {
    $module->override(new class extends AbstractModule {
        protected function configure(): void
        {
            $this->bind(LogWriterInterface::class)->toInstance(new class implements LogWriterInterface {
                #[Override]
                public function write(LogJson $log): void
                {
                    throw new RuntimeException('the log volume is not mounted');
                }
            });
        }
    });
}

$injector = new Injector($module, dirname(__DIR__) . '/tmp');
$injector->getInstance(ResourceInterface::class)->get('page://self/html/blog-posting');

if (in_array('--exit', $argv, true)) {
    exit(0); // the 304 path: the bootstrap stops here
}
