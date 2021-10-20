<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ReturnValueIsNotResourceObjectException;
use BEAR\QueryRepository\Exception\UnmatchedQuery;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use FakeVendor\HelloWorld\Resource\App\Code;
use FakeVendor\HelloWorld\Resource\App\RefreshDest;
use FakeVendor\HelloWorld\Resource\App\TypedParam;
use FakeVendor\HelloWorld\Resource\App\User\Profile;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function assert;

class BehaviorTest extends TestCase
{
    /** @var ResourceInterface */
    private $resource;

    /** @var HttpCacheInterface */
    private $httpCache;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new QueryRepositoryModule(new ResourceModule($namespace)), $_ENV['TMP_DIR']);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterface::class);
        parent::setUp();
    }

    public function testPurgeSameResourceObjectByPatch(): void
    {
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        assert($user instanceof ResourceObject);
        $etag = $user->headers[Header::ETAG];
        // reload (purge repository entry and re-generate by onGet)
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'kuma']);
        // load from repository, not invoke onGet method
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers[Header::ETAG];
        $this->assertFalse($etag === $newEtag);

        // patch request with invalid query
        $lastModified = $user->headers['Last-Modified'];
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => '']);
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $this->assertSame($lastModified, $user->headers['Last-Modified']);
    }

    public function testPurgeSameResourceObjectByDelete(): void
    {
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        assert($user instanceof ResourceObject);
        $etag = $user->headers[Header::ETAG];
        $server = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $etag,
        ];
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertTrue($isNotModified);
        $this->resource->delete('app://self/user', ['id' => 1]);
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers[Header::ETAG];
        $this->assertFalse($etag === $newEtag);
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertFalse($isNotModified);
    }

    public function testPurgeByAnnotation(): void
    {
        $this->resource->put('app://self/user', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
        $this->assertTrue(Profile::$requested);
    }

    public function testReturnValueIsNotResourceObjectException(): void
    {
        $this->expectException(ReturnValueIsNotResourceObjectException::class);
        $this->resource->put('app://self/invalid', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
    }

    public function testUnMatchQuery(): void
    {
        $this->expectException(UnmatchedQuery::class);
        $this->resource->put('app://self/unmatch', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
    }

    public function testCacheCode(): void
    {
        $ro = $this->resource->get('app://self/code', []);
        assert($ro instanceof Code); // 1
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

    public function testRefreshWithCacheableAnnotation(): void
    {
        RefreshDest::$id = 0;
        $this->resource->put('app://self/refresh-cache-src', ['id' => '1']);
        $this->assertSame('1', RefreshDest::$id);
    }

    public function testRefreshWithoutCacheableAnnotation(): void
    {
        RefreshDest::$id = 0;
        $this->resource->put('app://self/refresh-src', ['id' => '1']);
        $this->assertSame('1', RefreshDest::$id);
    }

    public function testRefreshByAbortedRequest(): void
    {
        $profile = $this->resource->get('app://self/user/profile', ['user_id' => 1]);
        $lastModified = $profile->headers['Last-Modified'];

        $this->resource->put('app://self/entry', ['id' => 1, 'name' => 'foo', 'age' => 'one']);
        $profile = $this->resource->get('app://self/user/profile', ['user_id' => 1]);
        $this->assertSame($lastModified, $profile->headers['Last-Modified']);
    }

    public function testRefreshTypedParam(): void
    {
        $this->resource->put('app://self/typed-param', ['id' => '1']);
        $this->assertSame(1, TypedParam::$id);
    }
}
