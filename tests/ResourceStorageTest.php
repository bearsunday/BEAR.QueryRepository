<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\InvalidateContext;
use BEAR\QueryRepository\Log\NullSemanticLogger;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function assert;

class ResourceStorageTest extends TestCase
{
    private ResourceStorage $storage;
    private Index $ro;

    public static function getResourceStorageInstance(SemanticLoggerInterface|null $logger = null, PurgerInterface|null $purger = null): ResourceStorage
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
            $logger ?? new NullSemanticLogger(),
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
        $logger = new RecordingSemanticLogger();
        $storage = self::getResourceStorageInstance($logger);

        $storage->invalidateTags(['_user_']);

        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertTrue($context->roPoolInvalidated);
        $this->assertTrue($context->etagPoolInvalidated);
        $this->assertTrue($context->cdnPurged);
        $this->assertGreaterThanOrEqual(0, $context->durationMs);
        $this->assertSame('purged', $context->jsonSerialize()['cdn']);
    }

    public function testInvalidateTagsTreatsPurgerFailureAsBestEffort(): void
    {
        $logger = new RecordingSemanticLogger();
        $storage = self::getResourceStorageInstance($logger, new FakeThrowingPurger());

        // A CDN purger outage must NOT fail local invalidation: the local pools are
        // already invalidated, so invalidateTags returns true without throwing...
        $result = $storage->invalidateTags(['_user_']);
        $this->assertTrue($result);

        // ...and the purge failure is recorded (not masked) as cdn=failed.
        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertTrue($context->roPoolInvalidated);
        $this->assertTrue($context->etagPoolInvalidated);
        $this->assertFalse($context->cdnPurged);
        $this->assertSame('failed', $context->jsonSerialize()['cdn']);
    }
}
