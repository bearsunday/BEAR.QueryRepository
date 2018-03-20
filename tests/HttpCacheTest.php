<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use Doctrine\Common\Cache\VoidCache;
use PHPUnit\Framework\TestCase;

class HttpCacheTest extends TestCase
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
