<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\User\Profile;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class QueryRepositoryTest extends TestCase
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

    protected function setUp()
    {
        $namespace = 'FakeVendor\HelloWorld';
        $injector = new Injector(new QueryRepositoryModule(new MobileEtagModule(new ResourceModule($namespace))), $_ENV['TMP_DIR']);
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterface::class);
        parent::setUp();
    }

    public function testPurgeSameResourceObjectByPatch()
    {
        /** @var ResourceObject $user */
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $etag = $user->headers['ETag'];
        // reload (purge repository entry and re-generate by onGet)
        $this->resource->patch->uri('app://self/user')->withQuery(['id' => 1, 'name' => 'kuma'])->eager->request();
        // load from repository, not invoke onGet method
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $newEtag = $user->headers['ETag'];
        $this->assertFalse($etag === $newEtag);
    }

    public function testPurgeSameResourceObjectByDelete()
    {
        /** @var ResourceObject $user */
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $etag = $user->headers['ETag'];
        $server = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $etag
        ];
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertTrue($isNotModified);
        $this->resource->delete->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $newEtag = $user->headers['ETag'];
        $this->assertFalse($etag === $newEtag);
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertFalse($isNotModified);
    }

    public function testPurgeByAnnotation()
    {
        $this->resource->put->uri('app://self/user')->withQuery(['id' => 1, 'age' => 10, 'name' => 'Sunday'])->eager->request();
        $this->assertTrue(Profile::$requested);
    }

    /**
     * @covers \BEAR\QueryRepository\QueryRepository::getExpiryTime()
     */
    public function testNoAnnotationLifeTime()
    {
        $ro = new None; // no annotation
        $ro->uri = new Uri('page://self/none');
        $result = $this->repository->put($ro);
        $this->assertTrue($result);
    }

    public function testPutResquestEmbeddedResoureView()
    {
        $uri = new Uri('page://self/emb-view');
        $ro = $this->resource->uri($uri)();
        $this->repository->put($ro);
        list(, , , $body, $view) = $this->repository->get($uri);
        $this->assertInstanceOf(None::class, $body['time']);
        $this->assertSame(1, $body['num']);
        $this->assertSame('{
    "time": null,
    "num": 1
}
', $view);
    }

    public function testPutResquestEmbeddedResoureValue()
    {
        $uri = new Uri('page://self/emb-val');
        $ro = $this->resource->uri($uri)();
        $this->repository->put($ro);
        list(, , , $body, $view) = $this->repository->get($uri);
        $this->assertInstanceOf(None::class, $body['time']);
        $this->assertSame(1, $body['num']);
        $this->assertNull($view);
    }
}
