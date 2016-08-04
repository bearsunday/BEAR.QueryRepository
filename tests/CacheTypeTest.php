<?php

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceClientFactory;
use BEAR\Resource\ResourceFactory;
use BEAR\Resource\ResourceInterface;
use Ray\Di\Injector;

class CacheTypeTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ResourceInterface
     */
    private $resource;

    public function setUp()
    {
        $this->resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'))))->getInstance(ResourceInterface::class);
        parent::setUp();
    }

    public function requestDobule($uri)
    {
        $ro = $this->resource->get->uri($uri)->eager->request();
        // put
        $expect = 'Last-Modified';
        $this->assertArrayHasKey($expect, $ro->headers);
        $time = $ro['time'];
        // get
        $ro = $this->resource->get->uri($uri)->eager->request();
        $this->assertArrayHasKey($expect, $ro->headers);
        $expect = $time;
        $this->assertSame($expect, $ro['time']);

        return $ro;
    }

    public function testValue()
    {
        $uri = 'app://self/value';
        // put
        $ro = $this->resource->get->uri($uri)->eager->request();
        (string) $ro;
        $time = $ro['time'];
        $this->assertSame('1' . $time, $ro->view);
        $ro = $this->resource->get->uri($uri)->eager->request();
        (string) $ro;
        $this->assertSame('2' . $time, $ro->view);
    }

    public function testView()
    {
        $uri = 'app://self/view';
        // put
        $ro = $this->resource->get->uri($uri)->eager->request();
        (string) $ro;
        $time = $ro['time'];
        $this->assertSame('1' . $time, $ro->view);
        $ro = $this->resource->get->uri($uri)->eager->request();
        (string) $ro;
        $this->assertSame('1' . $time, $ro->view);
    }
}
