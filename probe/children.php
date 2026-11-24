<?php

declare(strict_types=1);

// usage: php probe/children.php <page の名前> ...（例: probe-cacheable-response-too cacheable-response-with-material）

use BEAR\QueryRepository\ModuleFactory;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';

$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$injector = new Injector($module, dirname(__DIR__) . '/tests/tmp');
$resource = $injector->getInstance(ResourceInterface::class);
$logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);

function events(array $node, array $types): iterable
{
    foreach ($node['log'] ?? [] as $entry) {
        if (isset($entry['type']) && in_array($entry['type'], $types, true)) {
            yield $entry;
        }
        yield from events($entry, $types);
    }
}

foreach ($argv[1] ? array_slice($argv, 1) : [] as $page) {
    $ro = $resource->get('page://self/html/' . $page);
    echo $page, ' ', $ro->code, "\n";
    foreach ($ro->headers as $name => $value) {
        echo "  $name: $value\n";
    }
    $tree = $logger->flush()->toArray();
    foreach (events($tree, ['dependency_skipped', 'cdn_headers']) as $e) {
        echo '  [', $e['type'], '] ', json_encode($e['context'] ?? $e, JSON_UNESCAPED_SLASHES), "\n";
    }
}
