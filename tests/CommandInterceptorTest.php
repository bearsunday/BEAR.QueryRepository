<?php

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceClientFactory;
use BEAR\Resource\ResourceObject;
use Ray\Di\Injector;

class CommandInterceptorTest extends \PHPUnit_Framework_TestCase
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
        $this->resource = (new ResourceClientFactory)->newClient($_ENV['TMP_DIR'], 'FakeVendor\HelloWorld', $module);
        $this->repository = (new Injector($module, $_ENV['TMP_DIR']))->getInstance(QueryRepositoryInterface::class);
        parent::setUp();
    }

    public function testPatch()
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

    public function testDelete()
    {
        /** @var $user ResourceObject */
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $etag = $user->headers['Etag'];
        $this->resource->delete->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $user = $this->resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();
        $newEtag = $user->headers['Etag'];
        $this->assertFalse($etag === $newEtag);
    }
}
