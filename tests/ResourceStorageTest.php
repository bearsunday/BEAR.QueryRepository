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
use RuntimeException;
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

    public function testInvalidateTagsFailsClosedWhenPurgerFails(): void
    {
        $logger = new RecordingSemanticLogger();
        $storage = self::getResourceStorageInstance($logger, new FakeThrowingPurger());

        // The CDN purge is fail-closed: a purge failure propagates so the write does not
        // silently leave stale CDN content. The local pools are invalidated first and the
        // outcome is logged as cdn=failed before the exception surfaces.
        try {
            $storage->invalidateTags(['_user_']);
            $this->fail('Expected the purger failure to propagate (fail-closed)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('purge failed', $e->getMessage());
        }

        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertTrue($context->roPoolInvalidated);
        $this->assertTrue($context->etagPoolInvalidated);
        $this->assertFalse($context->cdnPurged);
        $this->assertSame('failed', $context->jsonSerialize()['cdn']);
    }

    public function testEtagIsNotRegisteredAsInvalidationTag(): void
    {
        $this->ro->headers['ETag'] = 'test-etag-value';
        $this->storage->saveValue($this->ro, 0);

        // ETag is not an invalidation tag: invalidating by the ETag value must NOT purge the entry.
        $this->storage->invalidateTags(['test-etag-value']);
        $this->assertNotNull($this->storage->get($this->ro->uri));

        // The URI tag still invalidates the same entry.
        $this->storage->invalidateTags([(new UriTag())($this->ro->uri)]);
        $this->assertNull($this->storage->get($this->ro->uri));
    }
}
