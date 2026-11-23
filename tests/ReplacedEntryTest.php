<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;

class ReplacedEntryTest extends TestCase
{
    use SemanticLogTreeTrait;

    private ResourceInterface $resource;
    private SemanticLoggerInterface $logger;
    private ResourceStorageInterface $storage;
    private ReplacedEntry $replacedEntry;

    protected function setUp(): void
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->logger = $injector->getInstance(SemanticLoggerInterface::class, CacheLog::class);
        $this->storage = $injector->getInstance(ResourceStorageInterface::class);
        $this->replacedEntry = $injector->getInstance(ReplacedEntry::class);
    }

    public function testAWriteIntoAnEmptySlotDropsNothing(): void
    {
        $ro = $this->resource->get('app://self/unstorable-value', ['unstorable' => false]);
        $this->storage->invalidateTags([(new UriTag(new CacheIdentityFactory(new GlobalServerContext())))($ro->uri)]);
        $this->logger->flush();

        $this->assertTrue($this->replacedEntry->entry($ro, Storability::Shared));
        $this->assertFalse($this->replacedEntry->entry($ro, Storability::Unstorable));

        $this->assertSame(['put_skipped'], self::collectTypes($this->flushAndValidate($this->logger)));
    }

    public function testAPageWriteDropsTheDonutItReplaces(): void
    {
        $ro = $this->resource->get('page://self/html/donut-cache-with-material');
        $this->assertNull($this->storage->get($ro->uri));
        $this->assertNotNull($this->storage->getDonut($ro->uri));
        $this->logger->flush();

        $this->assertTrue($this->replacedEntry->page($ro, Storability::Shared));

        $this->assertSame(['pre_write_cleanup', 'manual_invalidate', 'invalidate', 'manual_invalidate_result'], self::collectTypes($this->flushAndValidate($this->logger)));
        $this->assertNull($this->storage->getDonut($ro->uri));
    }

    public function testAValueWriteLooksOnlyAtTheValueSlot(): void
    {
        $ro = $this->resource->get('page://self/html/donut-cache-with-material');
        $this->logger->flush();

        $this->assertTrue($this->replacedEntry->entry($ro, Storability::Shared));

        $this->assertSame([], self::collectTypes($this->flushAndValidate($this->logger)));
        $this->assertNotNull($this->storage->getDonut($ro->uri));
    }
}
