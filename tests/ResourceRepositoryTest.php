<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\QueryRepository\QueryRepository as Repository;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\FilesystemCache;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;

class ResourceRepositoryTest extends TestCase
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
        $this->repository = new Repository(new EtagSetter, new FilesystemCache($_ENV['TMP_DIR']), new AnnotationReader(), new Expiry(0, 0, 0));
        /* @var $resource Resource */
        $this->resourceObject = new Index;
        $this->resourceObject->uri = new Uri('page://self/user');
    }

    public function testPutAndGet()
    {
        // put
        $this->repository->put($this->resourceObject);
        $uri = $this->resourceObject->uri;
        // get
        list($uri, $code, $headers, $body) = $this->repository->get($uri);
        $this->assertSame((string) $uri, (string) $this->resourceObject->uri);
        $this->assertSame($code, $this->resourceObject->code);
        $this->assertArraySubset($this->resourceObject->headers, $headers);
        $this->assertArrayHasKey('Age', $headers);
        $this->assertSame($body, $this->resourceObject->body);
    }

    public function testDelete()
    {
        $this->repository->put($this->resourceObject);
        $uri = $this->resourceObject->uri;
        $this->repository->purge($uri);
        $instance = (bool) $this->repository->get($uri);
        $this->assertFalse($instance);
    }
}
