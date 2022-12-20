<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\FastlyCachePurgeModule;
use BEAR\QueryRepository\Cdn\FastlyCachePurger;
use BEAR\Resource\ResourceInterface;
use Fastly\Api\PurgeApi;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function assert;
use function dirname;

class FastlyCachePurgeModuleTest extends TestCase
{
    public function testModule(): void
    {
        $module = new FastlyCachePurgeModule('apiKey', 'serviceId', true);
        $injector = new Injector($module, $_ENV['TMP_DIR']);

        $this->assertInstanceOf(PurgeApi::class, $injector->getInstance(PurgeApi::class));
        $this->assertInstanceOf(PurgerInterface::class, $injector->getInstance(FastlyCachePurger::class));
    }

    public function testPurge(): void
    {
        $module = $this->getModule();
        $module->override(new FakeFastlyCachePurgeModule('apiKey', 'serviceId', true));
        $injector =  new Injector($module, $_ENV['TMP_DIR']);
        $resource = $injector->getInstance(ResourceInterface::class);
        $resource->get('page://self/html/blog-posting');

        $api = $injector->getInstance(PurgeApi::class);
        assert($api instanceof FakeFastlyPurgeApi);
        $this->assertIsArray($api->logs);

        $this->assertSame(1, $api->logs[0]['fastly_soft_purge']);
        $this->assertSame('serviceId', $api->logs[0]['service_id']);
        $this->assertSame('_html_comment_', $api->logs[0]['purge_response']['surrogate_keys'][0]);
        $this->assertSame('_html_blog-posting_', $api->logs[1]['purge_response']['surrogate_keys'][0]);
    }

    private function getModule(): AbstractModule
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));

        return $module;
    }
}
