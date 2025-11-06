<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\QueryRepository as Repository;
use BEAR\Resource\Uri;
use Doctrine\Common\Cache\CacheProvider;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;
use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function array_change_key_case;
use function assert;

use const CASE_LOWER;

class ResourceRepositoryTest extends TestCase
{
    private Repository $repository;
    private Index $ro;

    protected function setUp(): void
    {
        $tagAwareAdapter = new TagAwareAdapter(new FilesystemAdapter('', 0, __DIR__ . '/tmp'));
        $tagAwareAdapterProvider = new class ($tagAwareAdapter) implements ProviderInterface{
            public function __construct(private TagAwareAdapter $tagAwareAdapter)
            {
            }

            public function get()
            {
                return $this->tagAwareAdapter;
            }
        };
        $this->repository = new Repository(
            new RepositoryLogger(),
            new HeaderSetter(new EtagSetter(new CacheDependency(new UriTag()))),
            new ResourceStorage(
                new RepositoryLogger(),
                new NullPurger(),
                new UriTag(),
                new ResourceStorageSaver(),
                $tagAwareAdapterProvider,
                $tagAwareAdapterProvider,
            ),
            new Expiry(0, 0, 0),
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

    public function testCreateFromDoctrineAnnotation(): void
    {
        // phpcs:disable SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingAnyTypeHint
        $doctrineCache = new class extends CacheProvider{
            protected function doFetch($id)
            {
            }

            protected function doContains($id)
            {
            }

            protected function doSave($id, $data, $lifeTime = 0)
            {
            }

            protected function doDelete($id)
            {
            }

            protected function doFlush()
            {
            }

            protected function doGetStats()
            {
            }
        };
        // phpcs:enable
        $tagAwareAdapter = new TagAwareAdapter(new NullAdapter());
        $tagAwareAdapterProvider = new class ($tagAwareAdapter) implements ProviderInterface{
            public function __construct(private TagAwareAdapter $tagAwareAdapter)
            {
            }

            public function get()
            {
                return $this->tagAwareAdapter;
            }
        };
        $repository = new Repository(
            new RepositoryLogger(),
            new HeaderSetter(new EtagSetter(new CacheDependency(new UriTag()))),
            new ResourceStorage(
                new RepositoryLogger(),
                new NullPurger(),
                new UriTag(),
                new ResourceStorageSaver(),
                $tagAwareAdapterProvider,
                $tagAwareAdapterProvider,
            ),
            new Expiry(0, 0, 0),
        );
        $this->assertInstanceOf(Repository::class, $repository);
    }
}
