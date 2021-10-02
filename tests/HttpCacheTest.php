<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function assert;
use function http_response_code;

class HttpCacheTest extends TestCase
{
    public function testisNotModifiedFale(): CliHttpCache
    {
        $httpCache = new CliHttpCache(new ResourceStorage(new RepositoryLogger(), new NullEtagDeleter(), new ArrayAdapter()));
        $server = [];
        $this->assertFalse($httpCache->isNotModified($server));

        return $httpCache;
    }

    public function testisNotModifiedTrue(): CliHttpCache
    {
        $resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'))))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        assert($user instanceof ResourceObject);
        $storage = new ResourceStorage(new RepositoryLogger(), new NullEtagDeleter(), new ArrayAdapter());
        $storage->updateEtag($user->uri, $user->headers['ETag'], '', 10);
        $httpCache = new CliHttpCache($storage);
        $server = ['HTTP_IF_NONE_MATCH' => $user->headers['ETag']];
        $this->assertTrue($httpCache->isNotModified($server));

        return $httpCache;
    }

    /**
     * @depends testisNotModifiedTrue
     */
    public function testCliHttpCacheTransfer(CliHttpCache $httpCache): void
    {
        $this->expectOutputRegex('/\A304 Not Modified/');
        $httpCache->transfer();
    }

    /**
     * @depends testisNotModifiedTrue
     * @covers \BEAR\QueryRepository\HttpCache::transfer
     */
    public function testHttpCacheTransfer(): void
    {
        $httpCache = new HttpCache(new ResourceStorage(new RepositoryLogger(), new NullEtagDeleter(), new ArrayAdapter()));
        $httpCache->transfer();
        $this->assertSame(304, http_response_code());
    }

    /**
     * @depends testisNotModifiedTrue
     */
    public function testHeaderSetInCli(): void
    {
        $resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'))))->getInstance(ResourceInterface::class);
        $user = $resource->get('app://self/user', ['id' => 1]);
        assert($user instanceof ResourceObject);
        $storage = new ResourceStorage(new RepositoryLogger(), new NullEtagDeleter(), new ArrayAdapter());
        $storage->updateEtag($user->uri, $user->headers['ETag'], '', 10);
        $httpCache = new CliHttpCache($storage);
        $header = 'IF_NONE_MATCH=' . $user->headers['ETag'];
        $server = [
            'argc' => 4,
            'argv' => [3 => $header],
        ];
        $this->assertTrue($httpCache->isNotModified($server));
    }
}
