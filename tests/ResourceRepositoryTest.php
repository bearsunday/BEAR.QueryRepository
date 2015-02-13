<?php

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\QueryRepository as Repository;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceClientFactory;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\FilesystemCache;
use Ray\Di\Injector;

class ResourceRepositoryTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var QueryRepository
     */
    private $repository;

    /**
     * @var ResourceObject
     */
    private $resourceObject;

    public function setUp()
    {
        $this->repository = new Repository(new FilesystemCache($_ENV['TMP_DIR']));
        $resource = (new Injector(new QueryRepositoryModule(new ResourceModule('FakeVendor\HelloWorld'))))->getInstance(ResourceInterface::class);
        /** @var $resource Resource */
        $this->resourceObject = $resource->get->uri('app://self/user')->withQuery(['id' => 1])->eager->request();

    }

    public function testPutAndGet()
    {
        // put
        $this->repository->put($this->resourceObject);
        $uri = $this->resourceObject->uri;
        // get
        list($code, $headers, $body) = $this->repository->get($uri);
        $this->assertSame($code, $this->resourceObject->code);
        $this->assertSame($headers, $this->resourceObject->headers);
        $this->assertSame($body, $this->resourceObject->body);
    }

    public function testDelete()
    {
        $this->repository->put($this->resourceObject);
        $uri = $this->resourceObject->uri;
        $this->repository->purge($uri);
        $instance = $this->repository->get($uri);
        $this->assertFalse($instance);
    }
}
