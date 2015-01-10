<?php

namespace BEAR\QueryRepository;

use BEAR\Resource\Resource;
use BEAR\Resource\ResourceClientFactory;
use BEAR\Resource\ResourceFactory;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Resource\App\User\Profile;
use Ray\Di\Injector;

class BehaviorTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var Resource
     */
    private $resource;

    /**
     * @var QueryRepository
     */
    private $repository;

    public function setUp()
    {
        $module = new QueryRepositoryModule('FakeVendor\HelloWorld');
        $this->resource = (new ResourceFactory)->newInstance($_ENV['TMP_DIR'], 'FakeVendor\HelloWorld', $module);
        $this->repository = (new Injector($module, $_ENV['TMP_DIR']))->getInstance(QueryRepositoryInterface::class);
        parent::setUp();
    }

    public function testPurgeSameResourceObjectByPatch()
    {
        /** @var $user ResourceObject */
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $etag = $user->headers['Etag'];
        // reload (purge repository entry and re-generate by onGet)
        $this->resource->patch->uri('app://self/user')->withQuery(['id' => 1, 'name' => 'kuma'])->eager->request();
        // load from repository, not invoke onGet method
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $newEtag = $user->headers['Etag'];
        $this->assertFalse($etag === $newEtag);
    }

    public function testPurgeSameResourceObjectByDelete()
    {
        /** @var $user ResourceObject */
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $etag = $user->headers['Etag'];
        $this->resource->delete->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $newEtag = $user->headers['Etag'];
        $this->assertFalse($etag === $newEtag);
    }

    public function testPurgeByAnnotation()
    {
        $this->resource->put->uri('app://self/user')->withQuery(['id' => 1, 'age' => 10, 'name' => 'Sunday'])->eager->request();
        $this->assertTrue(Profile::$requested);
    }
}
