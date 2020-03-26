<?php

declare(strict_types=1);

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
    private $ro;

    protected function setUp() : void
    {
        $this->repository = new Repository(
            new EtagSetter,
            new ResourceStorage(
                new FilesystemCache($_ENV['TMP_DIR'])
            ),
            new AnnotationReader,
            new Expiry(0, 0, 0)
        );
        $this->ro = new Index;
        $this->ro->uri = new Uri('page://self/user');
    }

    public function testPutAndGet()
    {
        // put
        $this->repository->put($this->ro);
        $uri = $this->ro->uri;
        // get
        list($uri, $code, $headers, $body) = $this->repository->get($uri);
        $this->assertSame((string) $uri, (string) $this->ro->uri);
        $this->assertSame($code, $this->ro->code);
        $headers = array_change_key_case($headers, CASE_LOWER);
        $Roheaders = array_change_key_case($this->ro->headers, CASE_LOWER);
        $this->assertSame($headers['content-type'], $Roheaders['content-type']);
        $this->assertSame($headers['etag'], $Roheaders['etag']);
        $this->assertSame($headers['last-modified'], $Roheaders['last-modified']);
        $this->assertSame(0, $headers['age']);
        $this->assertArrayHasKey('age', $headers);
        $this->assertSame($body, $this->ro->body);
    }

    public function testDelete()
    {
        $this->repository->put($this->ro);
        $uri = $this->ro->uri;
        $this->repository->purge($uri);
        $instance = (bool) $this->repository->get($uri);
        $this->assertFalse($instance);
    }
}
