<?php

namespace BEAR\QueryRepository;

use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\User\Profile;
use FakeVendor\HelloWorld\Resource\Page\None;
use Ray\Di\Injector;

class QueryRepositoryTest extends \PHPUnit_Framework_TestCase
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
     * @var HttpCache
     */
    private $httpCache;

    public function setUp()
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
        /** @var $user ResourceObject */
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
        /** @var $user ResourceObject */
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
     * @covers BEAR\QueryRepository\QueryRepository::getExpiryTime()
     */
    public function testNoAnnotationLifeTime()
    {
        $ro = new None; // no annotation
        $ro->uri = new Uri('page://self/none');
        $result = $this->repository->put($ro);
        $this->assertTrue($result);
    }
}
