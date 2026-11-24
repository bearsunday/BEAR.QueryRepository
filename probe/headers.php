<?php

declare(strict_types=1);

use BEAR\QueryRepository\ModuleFactory;
use BEAR\Resource\ResourceInterface;
use Madapaja\TwigModule\TwigModule;
use Ray\Di\Injector;

require dirname(__DIR__) . '/vendor/autoload.php';

$module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
$module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
$resource = (new Injector($module, dirname(__DIR__) . '/tests/tmp'))->getInstance(ResourceInterface::class);
foreach (['probe-http-cache-only', 'probe-cacheable-response-too'] as $page) {
    $ro = $resource->get('page://self/html/' . $page);
    echo $page, ' ', $ro->code, "\n";
    foreach ($ro->headers as $name => $value) {
        echo "  $name: $value\n";
    }

    foreach ($ro->body as $rel => $child) {
        if ($child instanceof BEAR\Resource\AbstractRequest) {
            $c = $child();
            echo "  [$rel] ", json_encode($c->headers), "\n";
        }
    }
}
