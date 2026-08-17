<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\QueryRepository as Repository;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\NullSemanticLogger;
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
            public function __construct(private readonly TagAwareAdapter $tagAwareAdapter)
            {
            }

            public function get()
            {
                return $this->tagAwareAdapter;
            }
        };
        $this->repository = new Repository(
            new NullSemanticLogger(),
            new HeaderSetter(new EtagSetter()),
            new ResourceStorage(
                new NullSemanticLogger(),
                new NullPurger(),
                new UriTag(),
                new ResourceStorageSaver(),
                new GlobalServerContext(),
                $tagAwareAdapterProvider,
                $tagAwareAdapterProvider,
            ),
            new Expiry(0, 0, 0),
            new CacheDependency(new UriTag()),
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
        // A value entry stores the body, so no rendering happened and no Content-Type was set:
        // the header belongs to the render, which runs per response on both paths.
        $this->assertArrayNotHasKey('content-type', $headers);
        $this->assertSame($headers['etag'], $roHeaders['etag']);
        $this->assertSame($headers['last-modified'], $roHeaders['last-modified']);
        // Age is `time() - strtotime(Last-Modified)`, so put→get crossing a
        // second boundary on a slow runner can land on '1'. Either value is
        // a correct freshly-cached response — what matters is that the
        // header is present and small.
        $this->assertArrayHasKey('age', $headers);
        $this->assertContains($headers['age'], ['0', '1']);
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

    public function testTheValidatorFollowsTheBodyWhenNothingRendered(): void
    {
        // A value entry is stored without rendering, so the validator stands for the body. If that
        // fallback were dropped, every such resource would serialize a null view into one constant
        // string - a validator that never changes is a 304 for content that did.
        $this->ro->body = ['name' => 'bear'];
        $this->repository->put($this->ro);
        $first = $this->ro->headers[Header::ETAG];

        $this->ro->body = ['name' => 'panda'];
        $this->repository->put($this->ro);

        $this->assertNotSame($first, $this->ro->headers[Header::ETAG], 'a changed body must move the validator');
    }

    public function testCreateFromDoctrineAnnotation(): void
    {
        // phpcs:enable
        $tagAwareAdapter = new TagAwareAdapter(new NullAdapter());
        $tagAwareAdapterProvider = new class ($tagAwareAdapter) implements ProviderInterface{
            public function __construct(private readonly TagAwareAdapter $tagAwareAdapter)
            {
            }

            public function get()
            {
                return $this->tagAwareAdapter;
            }
        };
        $repository = new Repository(
            new NullSemanticLogger(),
            new HeaderSetter(new EtagSetter()),
            new ResourceStorage(
                new NullSemanticLogger(),
                new NullPurger(),
                new UriTag(),
                new ResourceStorageSaver(),
                new GlobalServerContext(),
                $tagAwareAdapterProvider,
                $tagAwareAdapterProvider,
            ),
            new Expiry(0, 0, 0),
            new CacheDependency(new UriTag()),
        );
        $this->assertInstanceOf(Repository::class, $repository);
    }
}
