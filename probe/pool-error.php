<?php

declare(strict_types=1);

use BEAR\QueryRepository\ModuleFactory;
use BEAR\QueryRepository\StorageRedisDsnModule;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/guard.php';

$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new StorageRedisDsnModule('redis://127.0.0.1:6379'));
$injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

function events(array $node): iterable
{
    foreach ($node['log'] ?? [] as $entry) {
        if (isset($entry['type']) && (str_starts_with($entry['type'], 'save') || $entry['type'] === 'pool_error')) {
            yield $entry;
        }
        yield from events($entry);
    }
}

foreach (['first', 'second'] as $round) {
    $ro = $resource->get('app://self/user', ['id' => 1]);
    echo $round, ' ', $ro->code, ' ETag=', $ro->headers['ETag'] ?? '-', "\n";
    foreach (events($logger->flush()->toArray()) as $e) {
        echo '  [', $e['type'], '] ', json_encode($e['context'] ?? $e, JSON_UNESCAPED_SLASHES), "\n";
    }
}
