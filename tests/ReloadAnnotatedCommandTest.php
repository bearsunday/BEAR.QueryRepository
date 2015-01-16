<?php

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceClientFactory;

class ReloadAnnotatedCommandTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Resource
     */
    private $resource;

    public function setUp()
    {
        $module = new QueryRepositoryModule('FakeVendor\HelloWorld');
        $this->resource = (new ResourceClientFactory)->newClient($_ENV['TMP_DIR'], 'FakeVendor\HelloWorld', $module);
        parent::setUp();
    }

    public function testInvoke()
    {
        $user = $this->resource->patch->uri('app://self/user')->withQuery(['id' => 1, 'name' => 'koriym'])->eager->request();
        // put
        $expect = 'Last-Modified';
        $this->assertArrayHasKey($expect, $user->headers);
        $time = $user['time'];
        // get
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $this->assertArrayHasKey($expect, $user->headers);
        $expect = $time;
        $this->assertSame($expect, $user['time']);
    }

}
