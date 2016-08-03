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

    public function testValue()
    {
        $ro = $this->resource->get->uri('app://self/value')->withQuery(['id' => 1])->eager->request();
        // put
        $expect = 'Last-Modified';
        $this->assertArrayHasKey($expect, $ro->headers);
        $time = $ro['time'];
        // get
        $ro = $this->resource->get->uri('app://self/value')->withQuery(['id' => 1])->eager->request();
        $this->assertArrayHasKey($expect, $ro->headers);
        $expect = $time;
        $this->assertSame($expect, $ro['time']);
        $this->assertNull($ro->view);
    }

    public function testView()
    {
        $ro = $this->resource->get->uri('app://self/view')->withQuery(['id' => 1])->eager->request();
        // put
        $expect = 'Last-Modified';
        $this->assertArrayHasKey($expect, $ro->headers);
        $time = $ro['time'];
        // get
        $ro = $this->resource->get->uri('app://self/view')->withQuery(['id' => 1])->eager->request();
        $this->assertArrayHasKey($expect, $ro->headers);
        $expect = $time;
        $this->assertSame($expect, $ro['time']);
        $this->assertSame('view', $ro->view);
    }
}
