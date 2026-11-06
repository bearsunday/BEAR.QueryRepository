<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Madapaja\TwigModule\TwigModule;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function dirname;

/** An ETag is kept only when the entry it validates is stored (#261) */
class EtagAfterEntryTest extends TestCase
{
    /** @return array{ResourceInterface, ResourceStorageInterface} */
    private function app(bool $refuseEntries): array
    {
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld'));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        if ($refuseEntries) {
            // The ETag pool falls back to the entry pool when unbound; a pool of its own keeps
            // accepting while the entry pool refuses, as a separate ETag store does in production.
            $module->override(new class extends AbstractModule {
                #[Override]
                protected function configure(): void
                {
                    $pool = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()));
                    $this->bind(TagAwareAdapterInterface::class)->annotatedWith(ResourceObjectPool::class)->toInstance($pool);
                    $this->bind(TagAwareAdapterInterface::class)->annotatedWith(EtagPool::class)->toInstance(new TagAwareAdapter(new ArrayAdapter()));
                }
            });
        }

        $injector = new Injector($module, __DIR__ . '/tmp');

        return [$injector->getInstance(ResourceInterface::class), $injector->getInstance(ResourceStorageInterface::class)];
    }

    private function etagOf(ResourceObject $ro): string
    {
        $this->assertArrayHasKey(Header::ETAG, $ro->headers);

        return $ro->headers[Header::ETAG];
    }

    public function testAValueEntryThePoolRefusesLeavesNoEtag(): void
    {
        [$resource, $storage] = $this->app(refuseEntries: true);

        $ro = $resource->get('app://self/user', ['id' => 1]);

        $this->assertFalse($storage->hasEtag($this->etagOf($ro)), 'a conditional request would be answered 304 for an uncached response');
    }

    public function testAViewEntryWhoseBodyTheMarshallerRefusesLeavesNoEtag(): void
    {
        [$resource, $storage] = $this->app(refuseEntries: false);

        $ro = $resource->get('app://self/unserializable-view');

        $this->assertFalse($storage->hasEtag($this->etagOf($ro)));
    }

    public function testADonutPageViewThePoolRefusesLeavesNoEtag(): void
    {
        [$resource, $storage] = $this->app(refuseEntries: true);

        $ro = $resource->get('page://self/html/blog-posting', ['id' => 0]);

        $this->assertFalse($storage->hasEtag($this->etagOf($ro)));
    }

    public function testAStoredEntryKeepsItsEtag(): void
    {
        [$resource, $storage] = $this->app(refuseEntries: false);

        $value = $resource->get('app://self/user', ['id' => 1]);
        $page = $resource->get('page://self/html/blog-posting', ['id' => 0]);

        $this->assertTrue($storage->hasEtag($this->etagOf($value)));
        $this->assertTrue($storage->hasEtag($this->etagOf($page)));
    }

    public function testAnEntryWithoutAValidatorIsStoredWithoutOne(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new class extends AbstractModule {
            #[Override]
            protected function configure(): void
            {
                $this->bind(EtagSetterInterface::class)->toInstance(new class implements EtagSetterInterface {
                    #[Override]
                    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null): void
                    {
                    }
                });
            }
        });
        $injector = new Injector($module, __DIR__ . '/tmp');

        $ro = $injector->getInstance(ResourceInterface::class)->get('app://self/user', ['id' => 1]);

        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
        $this->assertNotNull($injector->getInstance(ResourceStorageInterface::class)->get(new Uri('app://self/user?id=1')));
    }
}
