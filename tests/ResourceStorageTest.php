<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use PHPUnit\Framework\TestCase;
use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ResourceStorageTest extends TestCase
{
    private ResourceStorage $storage;
    private Index $ro;

    public static function getResourceStorageInstance(RepositoryLoggerInterface|null $logger = null, PurgerInterface|null $purger = null): ResourceStorage
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

        return new ResourceStorage(
            $logger ?? new RepositoryLogger(),
            $purger ?? new NullPurger(),
            new UriTag(),
            new ResourceStorageSaver(),
            new GlobalServerContext(),
            $tagAwareAdapterProvider,
            $tagAwareAdapterProvider,
        );
    }

    protected function setUp(): void
    {
        $this->storage = self::getResourceStorageInstance();
        $this->ro = new Index();
        $this->ro->uri = new Uri('page://self/user');
        $this->ro->body = [];
    }

    public function testSaveGetStatic(): void
    {
        $donut = ResourceDonut::create($this->ro, new DonutRenderer(), new SurrogateKeys(new Uri('app://self/')), null, false);
        $this->storage->saveDonut($this->ro->uri, $donut, null, []);
        $donut = $this->storage->getDonut($this->ro->uri);
        $this->assertInstanceOf(ResourceDonut::class, $donut);
    }

    public function testInvalidateTagsRecordsSuccessfulOutcome(): void
    {
        $logger = new RepositoryLogger();
        $storage = self::getResourceStorageInstance($logger);

        $storage->invalidateTags(['_user_']);

        $log = $logger->getLogs()[0];
        $this->assertSame('invalidate-etag', $log['op']);
        $this->assertTrue($log['roOk']);
        $this->assertTrue($log['etagOk']);
        $this->assertTrue($log['purgerOk']);
        $this->assertArrayHasKey('dur', $log);
    }

    public function testInvalidateTagsTreatsPurgerFailureAsBestEffort(): void
    {
        $logger = new RepositoryLogger();
        $storage = self::getResourceStorageInstance($logger, new FakeThrowingPurger());

        // A CDN purger outage must NOT fail local invalidation: the local pools are
        // already invalidated, so invalidateTags returns true without throwing...
        $result = $storage->invalidateTags(['_user_']);
        $this->assertTrue($result);

        // ...and the purge failure is recorded (not masked) as purgerOk=false.
        $log = $logger->getLogs()[0];
        $this->assertSame('invalidate-etag', $log['op']);
        $this->assertTrue($log['roOk']);
        $this->assertTrue($log['etagOk']);
        $this->assertFalse($log['purgerOk']);
    }
}
