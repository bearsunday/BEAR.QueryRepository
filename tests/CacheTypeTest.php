<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class CacheTypeTest extends TestCase
{
    /**
     * @var ResourceInterface
     */
    private $resource;

    protected function setUp() : void
    {
        $this->resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld')), $_ENV['TMP_DIR']))->getInstance(ResourceInterface::class);
        parent::setUp();
    }

    public function requestDobule($uri)
    {
        $ro = $this->resource->get($uri);
        // put
        $expect = 'Last-Modified';
        $this->assertArrayHasKey($expect, $ro->headers);
        $time = $ro['time'];
        // get
        $ro = $this->resource->get($uri);
        $this->assertArrayHasKey($expect, $ro->headers);
        $expect = $time;
        $this->assertSame($expect, $ro['time']);

        return $ro;
    }

    public function testValue()
    {
        $uri = 'app://self/value';
        // put
        $ro = $this->resource->get($uri);
        (string) $ro;
        $time = $ro['time'];
        $this->assertSame('1' . $time, $ro->view);
        $ro = $this->resource->get($uri);
        (string) $ro;
        $this->assertSame('2' . $time, $ro->view);
    }

    public function testView()
    {
        $uri = 'app://self/view';
        // put
        $ro = $this->resource->get($uri);
        $time = $ro['time'];
        $this->assertSame('1' . $time, $ro->view);
        $ro = $this->resource->get($uri);
        $this->assertTrue((bool) $ro->view);
        $this->assertSame('1' . $time, $ro->view);
    }
}
