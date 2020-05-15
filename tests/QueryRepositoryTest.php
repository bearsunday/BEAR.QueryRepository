<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\Module\ResourceModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Cache\CacheProvider;
use FakeVendor\HelloWorld\Resource\App\User\Profile;
use FakeVendor\HelloWorld\Resource\Page\None;
use PHPUnit\Framework\Error\Warning;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

class QueryRepositoryTest extends TestCase
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
     * @var HttpCacheInterface
     */
    private $httpCache;

    protected function setUp() : void
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
        /** @var ResourceObject $user */
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $etag = $user->headers['ETag'];
        // reload (purge repository entry and re-generate by onGet)
        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'kuma']);
        // load from repository, not invoke onGet method
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers['ETag'];
        $this->assertFalse($etag === $newEtag);
    }

    public function testPurgeSameResourceObjectByDelete()
    {
        /** @var ResourceObject $user */
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $etag = $user->headers['ETag'];
        $server = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $etag
        ];
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertTrue($isNotModified);
        $this->resource->delete('app://self/user', ['id' => 1]);
        $user = $this->resource->get('app://self/user', ['id' => 1]);
        $newEtag = $user->headers['ETag'];
        $this->assertFalse($etag === $newEtag);
        $isNotModified = $this->httpCache->isNotModified($server);
        $this->assertFalse($isNotModified);
    }

    public function testPurgeByAnnotation()
    {
        $this->resource->put('app://self/user', ['id' => 1, 'age' => 10, 'name' => 'Sunday']);
        $this->assertTrue(Profile::$requested);
    }

    /**
     * @covers \BEAR\QueryRepository\QueryRepository::getExpiryTime()
     */
    public function testNoAnnotationLifeTime()
    {
        $ro = new None; // no annotation
        $ro->uri = new Uri('page://self/none');
        $result = $this->repository->put($ro);
        $this->assertTrue($result);
    }

    public function testPutResquestEmbeddedResoureView()
    {
        $uri = 'page://self/emb-view';
        $ro = $this->resource->get($uri);
        $this->repository->put($ro);
        [, , , $body, $view] = $this->repository->get(new Uri($uri));
        $this->assertInstanceOf(None::class, $body['time']);
        $this->assertSame(1, $body['num']);
        $this->assertSame('{
    "time": null,
    "num": 1
}
', $view);
    }

    public function testPutResquestEmbeddedResoureValue()
    {
        $uri = 'page://self/emb-val';
        $ro = $this->resource->get($uri);
        $this->repository->put($ro);
        [, , , $body, $view] = $this->repository->get(new Uri($uri));
        $this->assertInstanceOf(None::class, $body['time']);
        $this->assertSame(1, $body['num']);
        $this->assertNull($view);
    }

    public function testErrorInCacheRead()
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new QueryRepositoryModule(new MobileEtagModule(new ResourceModule($namespace)));

        $module->override(new class extends AbstractModule {
            protected function configure()
            {
                $this->bind(CacheProvider::class)->annotatedWith(Storage::class)->to(FakeErrorCache::class);
            }
        });
        $resource = (new Injector($module, $_ENV['TMP_DIR']))->getInstance(ResourceInterface::class);
        assert($resource instanceof ResourceInterface);
        $this->expectException(Warning::class);
        $resource->get('app://self/user', ['id' => 1]);
        $this->assertSame(2, $GLOBALS['BEAR\QueryRepository\syslog'][0]);
        $this->assertContains('Exception: DoctrineNamespaceCacheKey[]', $GLOBALS['BEAR\QueryRepository\syslog'][1]);
    }

    public function testSameResponseButDifferentParameter()
    {
        $ro1 = $this->resource->get('app://self/sometimes-same-response', ['id' => 1]);
        $server1 = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $ro1->headers['ETag'],
        ];
        $this->assertTrue($this->httpCache->isNotModified($server1), 'id:1 is not modified');

        $ro2 = $this->resource->get('app://self/sometimes-same-response', ['id' => 2]);
        $server2 = [
            'REQUEST_METHOD' => 'GET',
            'HTTP_IF_NONE_MATCH' => $ro2->headers['ETag'],
        ];
        $this->assertTrue($this->httpCache->isNotModified($server2), 'id:2 is not modified');

        $this->resource->delete('app://self/sometimes-same-response', ['id' => 1]);

        $this->assertFalse($this->httpCache->isNotModified($server1), 'id:1 is modified');
        $this->assertTrue($this->httpCache->isNotModified($server2), 'id:2 is not modified');
    }
}
