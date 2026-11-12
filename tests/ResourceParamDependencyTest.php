<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Entity\Catalog;
use FakeVendor\HelloWorld\Resource\App\User;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

/** A write to the resource a #[ResourceParam] reads invalidates the stored resource that read it */
class ResourceParamDependencyTest extends TestCase
{
    private ResourceInterface $resource;
    private QueryRepositoryInterface $repository;
    private ResourceStorageInterface $storage;

    protected function setUp(): void
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->repository = $injector->getInstance(QueryRepositoryInterface::class);
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        User::$data = User::DATA;
        Catalog::reset();

        parent::setUp();
    }

    /** @return array<string, array{0: string, 1: bool}> uri, whether the entry is donut-stored */
    public static function readerProvider(): array
    {
        return [
            'class #[Cacheable], on the parameter' => ['app://self/assisted-page?id=a', false],
            'class #[Cacheable], on the method' => ['app://self/assisted/on-method?id=a', false],
            'class #[Cacheable], on the method and on the parameter' => ['app://self/assisted/on-both?id=a', false],
            'method #[CacheableResponse]' => ['app://self/assisted/mr?id=a', true],
            'putStatic() in onGet()' => ['app://self/assisted/ps?id=a', true],
            '#[Cacheable] page embedding an uncached reader' => ['app://self/assisted/page', false],
            'method #[CacheableResponse] page reading an uncached reader' => ['app://self/assisted/page-donut', true],
            'page <- uncached mid <- uncached reader' => ['app://self/assisted/page-chain', false],
        ];
    }

    #[DataProvider('readerProvider')]
    public function testAWriteToTheResourceItReadsInvalidatesTheReader(string $uri, bool $isDonut): void
    {
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, $isDonut));

        $this->resource->patch('app://self/user', ['id' => 2, 'name' => 'panda']);
        $this->resource->put('app://self/rows/taxonomy?ulid=c1');
        $this->assertTrue($this->isStored($uri, $isDonut), 'a write to a resource it does not read must not invalidate the reader');

        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'koala']);
        $this->assertFalse($this->isStored($uri, $isDonut));
    }

    public function testTheReaderAnswersWithTheWrittenValue(): void
    {
        $uri = 'app://self/assisted-page?id=a';
        $this->assertSame(['v' => 'a:bear'], $this->resource->get($uri)->body);

        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'koala']);

        $this->assertSame(['v' => 'a:koala'], $this->resource->get($uri)->body);
    }

    public function testATemplatedUriNamesTheResourceFromTheRequestQuery(): void
    {
        $one = 'app://self/assisted/templated?id=1';
        $two = 'app://self/assisted/templated?id=2';
        $this->resource->get($one);
        $this->resource->get($two);

        $this->resource->patch('app://self/user', ['id' => 1, 'name' => 'koala']);

        $this->assertFalse($this->isStored($one, false));
        $this->assertTrue($this->isStored($two, false), 'the entry that read user?id=2 must outlive a write to user?id=1');
    }

    /** The reader takes over what the resource it reads depends on */
    public function testAWriteThatInvalidatesTheResourceItReadsInvalidatesTheReader(): void
    {
        $uri = 'app://self/assisted/from-list';
        $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, false));

        $this->resource->put('app://self/rows/taxonomy?ulid=c2');
        $this->assertTrue($this->isStored($uri, false));

        $this->resource->put('app://self/rows/taxonomy?ulid=c1');
        $this->assertFalse($this->isStored($uri, false));
    }

    /** The view of a page is stored when the page is first rendered and again when it is recomposed from its donut */
    public function testAViewDependsOnWhatItsHoleReadWhenStoredAndWhenRecomposed(): void
    {
        $uri = new Uri('app://self/assisted/page-hole');
        foreach (['stored' => 'koala', 'recomposed' => 'panda'] as $round => $name) {
            $this->resource->get((string) $uri);
            $this->assertNotNull($this->storage->get($uri));

            $this->resource->patch('app://self/user', ['id' => 1, 'name' => $name]);
            $this->assertNull($this->storage->get($uri), "the {$round} view");
        }
    }

    /** A resource it reads that is not cached gives no signal, so the reader is stored and served as it was stored */
    public function testAReaderOfAResourceThatIsNotCachedIsStoredAsASnapshot(): void
    {
        $uri = 'app://self/assisted/from-uncached?id=a';
        $first = $this->resource->get($uri);
        $this->assertTrue($this->isStored($uri, false));

        $this->assertSame($first->body, $this->resource->get($uri)->body, 'the stored entry answers, though the counter it read has moved on');
    }

    private function isStored(string $uri, bool $isDonut): bool
    {
        $abstractUri = new Uri($uri);

        return $isDonut ? $this->storage->getDonut($abstractUri) !== null : $this->repository->get($abstractUri) !== null;
    }
}
