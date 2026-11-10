<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class DependencyAfterSaveTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->logger->flush();

        parent::setUp();
    }

    public function testADependencyAValuePageDeclaresWhileRenderingIsLogged(): void
    {
        $this->resource->get('page://self/html/render-time-dependency-value')->toString();
        $tree = $this->flushAndValidate($this->logger);

        $contexts = self::eventContextsJsonOf($tree, 'dependency_after_save');
        $this->assertCount(1, $contexts);
        $this->assertSame([
            'parent' => 'page://self/html/render-time-dependency-value',
            'child' => 'page://self/html/mutable-comment',
        ], json_decode($contexts[0], true, 512, JSON_THROW_ON_ERROR));
        $this->assertCount(1, self::eventContextsJsonOf($tree, 'depends_on'));
    }

    public function testADependencyAValuePageServedFromTheCacheDeclaresWhileRenderingIsLogged(): void
    {
        $this->resource->get('page://self/html/render-time-dependency-value')->toString();
        $this->logger->flush();

        $this->resource->get('page://self/html/render-time-dependency-value')->toString();
        $tree = $this->flushAndValidate($this->logger);

        $this->assertCount(1, self::eventContextsJsonOf($tree, 'dependency_after_save'));
    }

    /** @return array<string, array{string}> */
    public static function storedWithItsDependencyProvider(): array
    {
        return [
            'a view page rendering during its put' => ['page://self/html/render-time-dependency-view'],
            'a donut page rendering during its put' => ['page://self/html/render-time-dependency'],
            'a value page embedding its child' => ['page://self/dep/parent-a'],
        ];
    }

    #[DataProvider('storedWithItsDependencyProvider')]
    public function testADependencyDeclaredBeforeTheSaveIsNotLogged(string $uri): void
    {
        $this->resource->get($uri)->toString();
        $tree = $this->flushAndValidate($this->logger);

        $this->assertNotSame([], self::eventContextsJsonOf($tree, 'depends_on'));
        $this->assertSame([], self::eventContextsJsonOf($tree, 'dependency_after_save'));
    }
}
