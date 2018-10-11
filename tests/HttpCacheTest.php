<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use Doctrine\Common\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

class HttpCacheTest extends TestCase
{
    public function testisNotModifiedFale()
    {
        $httpCache = new HttpCache(new ArrayCache);
        $server = [];
        $this->assertFalse($httpCache->isNotModified($server));
    }

    public function testisNotModifiedTrue()
    {
        $cache = new ArrayCache;
        $uri = new Uri('app://self/');
        $etag = 'etag-1';
        $cache->save(HttpCache::ETAG_KEY . $etag, (string) $uri);
        $httpCache = new HttpCache($cache);
        $server = ['HTTP_IF_NONE_MATCH' => $etag];
        $this->assertTrue($httpCache->isNotModified($server));
    }
}
