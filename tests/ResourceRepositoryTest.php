<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\QueryRepository as Repository;
use BEAR\Resource\Uri;
use Doctrine\Common\Annotations\AnnotationReader;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;
use Ray\PsrCacheModule\FilesystemAdapter;

use function array_change_key_case;
use function assert;

use const CASE_LOWER;

class ResourceRepositoryTest extends TestCase
{
    /** @var QueryRepository */
    private $repository;

    /** @var Index */
    private $ro;

    protected function setUp(): void
    {
        $this->repository = new Repository(
            new EtagSetter(),
            new ResourceStorage(
                new FilesystemAdapter('', 0, $_ENV['TMP_DIR'])
            ),
            new AnnotationReader(),
            new Expiry(0, 0, 0)
        );
        $this->ro = new Index();
        $this->ro->uri = new Uri('page://self/user');
    }

    public function testPutAndGet(): void
    {
        // put
        $this->repository->put($this->ro);
        $uri = $this->ro->uri;
        // get
        $state = $this->repository->get($uri);
        assert($state instanceof ResourceState);
        $this->assertSame((string) $uri, (string) $this->ro->uri);
        $this->assertSame($state->code, $this->ro->code);
        $headers = array_change_key_case($state->headers, CASE_LOWER);
        $roHeaders = array_change_key_case($this->ro->headers, CASE_LOWER);
        $this->assertSame($headers['content-type'], $roHeaders['content-type']);
        $this->assertSame($headers['etag'], $roHeaders['etag']);
        $this->assertSame($headers['last-modified'], $roHeaders['last-modified']);
        $this->assertSame('0', $headers['age']);
        $this->assertArrayHasKey('age', $headers);
        $this->assertSame($state->body, $this->ro->body);
    }

    public function testDelete(): void
    {
        $this->repository->put($this->ro);
        $uri = $this->ro->uri;
        $instance = $this->repository->get($uri);
        $this->assertInstanceOf(ResourceState::class, $instance);
        $this->repository->purge($uri);
        $instance = (bool) $this->repository->get($uri);
        $this->assertFalse($instance);
    }
}
