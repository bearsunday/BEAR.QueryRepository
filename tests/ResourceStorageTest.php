<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\InvalidateContext;
use BEAR\QueryRepository\Log\Context\SaveDonutContext;
use BEAR\QueryRepository\Log\Context\SaveDonutViewContext;
use BEAR\QueryRepository\Log\Context\SaveEtagContext;
use BEAR\QueryRepository\Log\Context\SaveValueContext;
use BEAR\QueryRepository\Log\Context\SaveViewContext;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\ProviderInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

use function assert;

class ResourceStorageTest extends TestCase
{
    private ResourceStorage $storage;
    private Index $ro;

    public static function getResourceStorageInstance(
        SemanticLoggerInterface|null $logger = null,
        PurgerInterface|null $purger = null,
        TagAwareAdapterInterface|null $roPool = null,
        TagAwareAdapterInterface|null $etagPool = null,
    ): ResourceStorage {
        $tagAwareAdapter = new TagAwareAdapter(new FilesystemAdapter('', 0, __DIR__ . '/tmp'));
        $provider = static function (TagAwareAdapterInterface $pool): ProviderInterface {
            return new class ($pool) implements ProviderInterface {
                public function __construct(private readonly TagAwareAdapterInterface $pool)
                {
                }

                public function get()
                {
                    return $this->pool;
                }
            };
        };

        return new ResourceStorage(
            $logger ?? new NullSemanticLogger(),
            $purger ?? new NullPurger(),
            new UriTag(),
            new ResourceStorageSaver(),
            new GlobalServerContext(),
            $provider($roPool ?? $tagAwareAdapter),
            $provider($etagPool ?? $tagAwareAdapter),
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

    public function testInvalidateTagsWithNullPurgerLogsCdnSkipped(): void
    {
        $logger = new RecordingSemanticLogger();
        $storage = self::getResourceStorageInstance($logger);

        $storage->invalidateTags(['_user_']);

        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertTrue($context->roPoolInvalidated);
        $this->assertTrue($context->etagPoolInvalidated);
        // The default NullPurger is a no-op: the CDN side is "skipped", not "purged" —
        // nothing was purged, but nothing was meant to be.
        $this->assertSame('skipped', $context->cdnStatus);
        $this->assertSame('skipped', $context->jsonSerialize()['cdn']);
        $this->assertGreaterThanOrEqual(0, $context->durationMs);
    }

    public function testInvalidateTagsLogsCdnPurgedWithConfiguredPurger(): void
    {
        $logger = new RecordingSemanticLogger();
        $purger = new class implements PurgerInterface {
            /** @var list<string> */
            public array $tags = [];

            #[Override]
            public function __invoke(string $tag): void
            {
                $this->tags[] = $tag;
            }
        };
        $storage = self::getResourceStorageInstance($logger, $purger);

        $storage->invalidateTags(['_user_']);

        $this->assertSame(['_user_'], $purger->tags, 'the configured purger received the surrogate keys');
        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertSame('purged', $context->cdnStatus, 'a real purger that ran without error logs "purged"');
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
        $this->assertSame('failed', $context->cdnStatus);
        $this->assertSame('failed', $context->jsonSerialize()['cdn']);
    }

    public function testHasEtagAcceptsIfNoneMatchVariants(): void
    {
        $this->storage->saveEtag($this->ro->uri, '"123456"', '', 10);

        $this->assertTrue($this->storage->hasEtag('"123456"'), 'quoted entity-tag');
        $this->assertTrue($this->storage->hasEtag('123456'), 'bare legacy token');
        $this->assertTrue($this->storage->hasEtag('W/"123456"'), 'weak validator');
        $this->assertTrue($this->storage->hasEtag('"999999", "123456"'), 'entity-tag list');
        $this->assertFalse($this->storage->hasEtag('"999999"'), 'unknown entity-tag');
    }

    public function testHasEtagSplitsOnlyOnCommasOutsideQuotes(): void
    {
        $this->storage->saveEtag($this->ro->uri, '"foo,bar"', '', 10);

        $this->assertTrue($this->storage->hasEtag('"foo,bar"'), 'comma inside a quoted opaque-tag is data');
        $this->assertTrue($this->storage->hasEtag('W/"foo,bar"'), 'weak validator with comma in opaque-tag');
        $this->assertTrue($this->storage->hasEtag('"999999", "foo,bar"'), 'list with a comma-bearing entity-tag');
        $this->assertFalse($this->storage->hasEtag('"bar"'), 'naive split fragment must not match');
        $this->assertFalse(
            $this->storage->hasEtag('foo,bar'),
            'unquoted legacy value is indistinguishable from a two-element list (documented limitation)',
        );
    }

    public function testHasEtagRejectsMalformedFieldValues(): void
    {
        $this->storage->saveEtag($this->ro->uri, '"123456"', '', 10);

        $this->assertFalse($this->storage->hasEtag('"123456'), 'unterminated quoted entity-tag');
        $this->assertFalse($this->storage->hasEtag('"123456" trailing'), 'trailing data after entity-tag');
        $this->assertFalse($this->storage->hasEtag('x"123456"'), 'leading data before entity-tag');
        $this->assertFalse($this->storage->hasEtag('"123456" "123456"'), 'missing comma separator');
        $this->assertFalse($this->storage->hasEtag("\"123456\"\n"), 'trailing newline');
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

    public function testSaveValueLogsSavedFalseWhenPoolRejectsEntry(): void
    {
        // ResourceStorageSaver is final, so the failure is induced one layer down: an inner
        // pool whose commit() rejects every entry (e.g. storage full).
        $failingPool = new TagAwareAdapter(new class extends ArrayAdapter {
            #[Override]
            public function commit(): bool
            {
                return false;
            }
        });
        $poolProvider = new class ($failingPool) implements ProviderInterface {
            public function __construct(private readonly TagAwareAdapter $tagAwareAdapter)
            {
            }

            public function get()
            {
                return $this->tagAwareAdapter;
            }
        };
        $logger = new RecordingSemanticLogger();
        $storage = new ResourceStorage(
            $logger,
            new NullPurger(),
            new UriTag(),
            new ResourceStorageSaver(),
            new GlobalServerContext(),
            $poolProvider,
            $poolProvider,
        );

        $saved = $storage->saveValue($this->ro, 10);

        $this->assertFalse($saved, 'the store result surfaces to the caller');
        $context = $logger->events[0];
        assert($context instanceof SaveValueContext);
        $this->assertFalse($context->saved, 'the log records that the entry is NOT cached despite the save event');
        $this->assertSame(10, $context->ttl);
    }

    public function testSaveDonutLogsSavedFalseWhenPoolRejectsEntry(): void
    {
        // Same failure injection as the saveValue case above: an inner pool whose
        // commit() rejects every entry. The round-2 assert removal made saved:false
        // observable for donut stores; this pins it.
        $failingPool = new TagAwareAdapter(new class extends ArrayAdapter {
            #[Override]
            public function commit(): bool
            {
                return false;
            }
        });
        $poolProvider = new class ($failingPool) implements ProviderInterface {
            public function __construct(private readonly TagAwareAdapter $tagAwareAdapter)
            {
            }

            public function get()
            {
                return $this->tagAwareAdapter;
            }
        };
        $logger = new RecordingSemanticLogger();
        $storage = new ResourceStorage(
            $logger,
            new NullPurger(),
            new UriTag(),
            new ResourceStorageSaver(),
            new GlobalServerContext(),
            $poolProvider,
            $poolProvider,
        );
        $donut = ResourceDonut::create($this->ro, new DonutRenderer(), new SurrogateKeys(new Uri('app://self/')), null, false);

        $storage->saveDonut($this->ro->uri, $donut, null, []);

        $context = $logger->events[0];
        assert($context instanceof SaveDonutContext);
        $this->assertFalse($context->saved, 'the log records that the donut entry is NOT cached despite the save event');
    }

    public function testEveryStoreClampsNegativeTtlToZero(): void
    {
        $logger = new RecordingSemanticLogger();
        $storage = self::getResourceStorageInstance($logger);
        $donut = ResourceDonut::create($this->ro, new DonutRenderer(), new SurrogateKeys(new Uri('app://self/')), null, false);

        // The storage is a public interface: a caller handing it a lifetime that already
        // passed must not produce a negative ttl, which every save_* schema forbids
        // ("minimum": 0) and which no cache backend can honour anyway.
        $storage->saveValue($this->ro, -10);
        $storage->saveView($this->ro, -10);
        $storage->saveEtag($this->ro->uri, '"123456"', '', -10);
        $storage->saveDonutView($this->ro, -10);
        $storage->saveDonut($this->ro->uri, $donut, -10, []);

        $this->assertCount(5, $logger->events);
        foreach ($logger->events as $event) {
            $this->assertSame(0, self::ttlOf($event), 'every store clamps a negative ttl to 0');
        }
    }

    /** The stored lifetime an event records, whichever store emitted it */
    private static function ttlOf(AbstractContext $event): int|null
    {
        assert(
            $event instanceof SaveValueContext
            || $event instanceof SaveViewContext
            || $event instanceof SaveEtagContext
            || $event instanceof SaveDonutViewContext
            || $event instanceof SaveDonutContext,
        );

        return $event->ttl;
    }

    public function testInvalidateTagsFailsWhenOnlyOnePoolRefuses(): void
    {
        $logger = new RecordingSemanticLogger();
        $refusing = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refuseSave: false, refuseInvalidation: true);
        $storage = self::getResourceStorageInstance($logger, roPool: $refusing);

        // Fail-closed: one pool still holding the entry means the resource is not
        // invalidated, however well the other pool did.
        $this->assertFalse($storage->invalidateTags(['_user_']));

        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertFalse($context->roPoolInvalidated);
        $this->assertTrue($context->etagPoolInvalidated);
        // Both status words in one event: the pool that kept the entry must not read as
        // invalidated, and the one that dropped it must not read as failed
        $json = $context->jsonSerialize();
        $this->assertSame('failed', $json['roPool']);
        $this->assertSame('invalidated', $json['etagPool']);
    }
}
