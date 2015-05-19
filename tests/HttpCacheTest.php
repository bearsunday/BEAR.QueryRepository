<?php

namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\VoidCache;

class HttpCacheTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        parent::setUp();
    }

    public function testPurgeSameResourceObjectByPatch()
    {
        $httpCache = new HttpCache(new VoidCache);
        $server = [];
        $result = $httpCache->isNotModified($server);
        $this->assertFalse($result);
    }
}
