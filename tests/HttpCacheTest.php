<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use Doctrine\Common\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class HttpCacheTest extends TestCase
{
    public function testisNotModifiedFale()
    {
        $httpCache = new HttpCache(new ResourceStorage(new ArrayCache));
        $server = [];
        $this->assertFalse($httpCache->isNotModified($server));
    }

    public function testIsNotModifiedTrue()
    {
        $resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'))))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        $storage = new ResourceStorage(new ArrayCache);
        $storage->updateEtag($user);
        $httpCache = new HttpCache($storage);
        $server = ['HTTP_IF_NONE_MATCH' => $user->headers['ETag']];
        $this->assertTrue($httpCache->isNotModified($server));

        return $httpCache;
    }

    /**
     * @depends testisNotModifiedTrue
     */
    public function testTransfer(HttpCache $httpCache)
    {
        $this->expectOutputRegex('/\A304 Not Modified/');
        $httpCache->transfer();
    }
}
