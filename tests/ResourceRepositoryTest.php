<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\QueryRepository as Repository;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

use function array_change_key_case;
use function assert;

use const CASE_LOWER;

class ResourceRepositoryTest extends TestCase
{
    private QueryRepositoryInterface $repository;
    private Index $ro;
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $this->injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new StorageExpiryModule(0, 0, 0));
        $module->override(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(AdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new FilesystemAdapter('', 0, __DIR__ . '/tmp'));
            }
        });
        $this->repository = (new Injector($module, __DIR__ . '/tmp'))->getInstance(QueryRepositoryInterface::class);
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
        $this->assertInstanceOf(Repository::class, $this->injector->getInstance(QueryRepositoryInterface::class));
    }
}
