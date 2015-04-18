<?php

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceClientFactory;
use BEAR\Resource\ResourceInterface;
use Ray\Di\Injector;

class EtagSetterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var ResourceInterface
     */
    private $resource;

    public function setUp()
    {
        parent::setUp();
        $this->resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld')), $_ENV['TMP_DIR']))->getInstance(ResourceInterface::class);
    }

    public function testInvoke()
    {
        $resourceObject = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $setEtag = new EtagSetter;
        $time = 0;
        $setEtag($resourceObject, $time);
        $expect = 'Thu, 01 Jan 1970 00:00:01 GMT';
        $this->assertSame($expect, $resourceObject->headers['Last-Modified']);
        $this->assertInternalType('string', $resourceObject->headers['Etag']);
    }
}
