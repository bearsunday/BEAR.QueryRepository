<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Aop\ReflectiveMethodInvocation;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Throwable;

use function serialize;
use function unserialize;

class PutInProgressTest extends TestCase
{
    public function testEndRemovesTheMark(): void
    {
        $putInProgress = new PutInProgress();
        $ro = self::resourceObject();

        $putInProgress->begin($ro);
        $this->assertTrue($putInProgress->has($ro));

        $putInProgress->end($ro);
        $this->assertFalse($putInProgress->has($ro));
    }

    public function testANestedEndKeepsTheOuterMark(): void
    {
        $putInProgress = new PutInProgress();
        $ro = self::resourceObject();

        $putInProgress->begin($ro);
        $putInProgress->begin($ro);
        $putInProgress->end($ro);
        $this->assertTrue($putInProgress->has($ro));

        $putInProgress->end($ro);
        $this->assertFalse($putInProgress->has($ro));
    }

    public function testAnUnserializedInstanceHasNoMark(): void
    {
        $putInProgress = new PutInProgress();
        $ro = self::resourceObject();
        $putInProgress->begin($ro);

        $restored = unserialize(serialize($putInProgress));
        $this->assertInstanceOf(PutInProgress::class, $restored);
        $this->assertFalse($restored->has($ro));
    }

    public function testTheInterceptorMarksTheResourceOnlyWhileItIsRebuilt(): void
    {
        $injector = self::injector(false);
        $putInProgress = $injector->getInstance(PutInProgress::class);
        $ro = new class extends ResourceObject {
            public bool $markedInOnGet = false;
            public PutInProgress|null $putInProgress = null;

            public function onGet(): static
            {
                $this->markedInOnGet = (bool) $this->putInProgress?->has($this);

                return $this;
            }
        };
        $ro->uri = new Uri('page://self/put-in-progress/marked');
        $ro->putInProgress = $putInProgress;

        $injector->getInstance(CacheInterceptor::class)->invoke(new ReflectiveMethodInvocation($ro, 'onGet', []));

        $this->assertTrue($ro->markedInOnGet);
        $this->assertFalse($putInProgress->has($ro));
    }

    public function testTheInterceptorRemovesTheMarkWhenTheResourceThrows(): void
    {
        $injector = self::injector(false);
        $putInProgress = $injector->getInstance(PutInProgress::class);
        $ro = new class extends ResourceObject {
            public function onGet(): static
            {
                throw new RuntimeException('onGet failed');
            }
        };
        $ro->uri = new Uri('page://self/put-in-progress/throwing');

        $this->assertThrownWithoutAMark('onGet failed', $putInProgress, $ro, static function () use ($injector, $ro): void {
            $injector->getInstance(CacheInterceptor::class)->invoke(new ReflectiveMethodInvocation($ro, 'onGet', []));
        });
    }

    public function testThePutRemovesTheMarkWhenItIsStored(): void
    {
        $injector = self::injector(false);
        $putInProgress = $injector->getInstance(PutInProgress::class);
        $ro = self::resourceObject();

        $this->assertTrue($injector->getInstance(QueryRepositoryInterface::class)->put($ro));
        $this->assertFalse($putInProgress->has($ro));
    }

    public function testThePutRemovesTheMarkWhenTheStoreThrows(): void
    {
        $injector = self::injector(true);
        $putInProgress = $injector->getInstance(PutInProgress::class);
        $ro = self::resourceObject();

        $this->assertThrownWithoutAMark('cache server down', $putInProgress, $ro, static function () use ($injector, $ro): void {
            $injector->getInstance(QueryRepositoryInterface::class)->put($ro);
        });
    }

    /** @param callable(): void $run */
    private function assertThrownWithoutAMark(string $message, PutInProgress $putInProgress, ResourceObject $ro, callable $run): void
    {
        $thrown = null;
        try {
            $run();
        } catch (Throwable $e) {
            $thrown = $e->getPrevious() ?? $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame($message, $thrown->getMessage());
        $this->assertFalse($putInProgress->has($ro));
    }

    private static function resourceObject(): ResourceObject
    {
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('page://self/put-in-progress/stored');
        $ro->body = ['id' => 1];

        return $ro;
    }

    private static function injector(bool $throwOnSave): Injector
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new class ($throwOnSave) extends AbstractModule {
            public function __construct(private readonly bool $throwOnSave)
            {
                parent::__construct();
            }

            #[Override]
            protected function configure(): void
            {
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance(new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refuseSave: false, throwOnSave: $this->throwOnSave));
                $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toInstance(new TagAwareAdapter(new ArrayAdapter()));
            }
        });

        return new Injector($module, __DIR__ . '/tmp');
    }
}
