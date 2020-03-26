<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Resource\App\Code;
use FakeVendor\HelloWorld\Resource\App\RefreshDest;
use FakeVendor\HelloWorld\Resource\App\User\Profile;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class BehaviorTest extends TestCase
{
    /**
     * @var ResourceInterface
     */
    private $resource;

    /**
     * @var QueryRepository
     */
    private $repository;

    /**
     * @var HttpCacheInterface
     */
    private $httpCache;

    protected function setUp() : void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new QueryRepositoryModule(new ResourceModule($namespace)), $_ENV['TMP_DIR']);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterface::class);
        parent::setUp();
    }

    public function testPurgeSameResourceObjectByPatch()
    {
        /** @var ResourceObject $user */
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $etag = $user->headers['ETag'];
        // reload (purge repository entry and re-generate by onGet)
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'kuma']);
        // load from repository, not invoke onGet method
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers['ETag'];
        $this->assertFalse($etag === $newEtag);

        // patch request with invalid query
        $lastModified = $user->headers['Last-Modified'];
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => '']);
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $this->assertSame($lastModified, $user->headers['Last-Modified']);
    }

    public function testPurgeSameResourceObjectByDelete()
    {
        /** @var ResourceObject $user */
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $etag = $user->headers['ETag'];
        $server = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $etag
        ];
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertTrue($isNotModified);
        $this->resource->delete('app://self/user', ['id' => 1]);
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers['ETag'];
        $this->assertFalse($etag === $newEtag);
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertFalse($isNotModified);
    }

    public function testPurgeByAnnotation()
    {
        $this->resource->put('app://self/user', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
        $this->assertTrue(Profile::$requested);
    }

    public function testReturnValueIsNotResourceObjectException()
    {
        $this->expectException(ReturnValueIsNotResourceObjectException::class);
        $this->resource->put('app://self/invalid', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
    }

    public function testUnMatchQuery()
    {
        $this->expectException(UnmatchedQuery::class);
        $this->resource->put('app://self/unmatch', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
    }

    public function testCacheCode()
    {
        /** @var Code $ro */
        $ro = $this->resource->get('app://self/code', []); // 1
        $ro->code = 203;
        $ro->onGet(); // 2 non-caached
        $ro->code = 500;
        $ro->onGet(); // 3 non-caached
        $this->assertSame(3, Code::$i);
        $ro->code = 200;
        $ro->onGet(); // 4 cached
        $ro->onGet();
        $this->assertSame(4, Code::$i);
    }

    public function testRefreshWithCacheableAnnotation()
    {
        RefreshDest::$id = 0;
        $this->resource->put('app://self/refresh-cache-src', ['id' => '1']);
        $this->assertSame('1', RefreshDest::$id);
    }

    public function testRefreshWithoutCacheableAnnotation()
    {
        RefreshDest::$id = 0;
        $this->resource->put('app://self/refresh-src', ['id' => '1']);
        $this->assertSame('1', RefreshDest::$id);
    }

    public function testRefreshByAbortedRequest()
    {
        $profile = $this->resource->get('app://self/user/profile', ['user_id' => 1]);
        $lastModified = $profile->headers['Last-Modified'];

        $this->resource->put('app://self/entry', ['id' => 1, 'name' => 'foo', 'age' => 'one']);
        $profile = $this->resource->get('app://self/user/profile', ['user_id' => 1]);
        $this->assertSame($lastModified, $profile->headers['Last-Modified']);
    }
}
