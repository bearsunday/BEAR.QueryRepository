<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\InvalidateContext;
use BEAR\QueryRepository\Log\Context\SaveDonutContext;
use BEAR\QueryRepository\Log\Context\SaveDonutViewContext;
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

use function array_keys;
use function assert;
use function serialize;
use function str_repeat;
use function trim;
use function unserialize;

class ResourceStorageTest extends TestCase
{
    private ResourceStorage $storage;
    private Index $ro;

    public static function getResourceStorageInstance(
        SemanticLoggerInterface|null $logger = null,
        PurgerInterface|null $purger = null,
        TagAwareAdapterInterface|null $roPool = null,
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
            new ResourceStorageSaver(),
            new CacheIdentityFactory(new GlobalServerContext()),
            $provider($roPool ?? $tagAwareAdapter),
            new CacheTags(new UriTag(), new BaseUriTag(new UriTag())),
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

    public function testARestoredStorageStillWrites(): void
    {
        // A compiled injector is serialized to disk and restored per request, so every promoted
        // property has to survive the round trip. One left out stays uninitialized and the first
        // write throws - which the interceptor turns into a warning, so the cache silently never
        // fills while the responses stay correct.
        $serializable = new ResourceStorage(
            new NullSemanticLogger(),
            new NullPurger(),
            new ResourceStorageSaver(),
            new CacheIdentityFactory(new GlobalServerContext()),
            new FakeArrayPoolProvider(),
            new CacheTags(new UriTag(), new BaseUriTag(new UriTag())),
        );
        $storage = unserialize(serialize($serializable));
        assert($storage instanceof ResourceStorage);

        $this->assertTrue($storage->saveValue($this->ro, 60));
    }

    public function testADoubleSpacedSurrogateKeyStoresTheEntryAndItsEtagUnderTheRemainingKeys(): void
    {
        $this->ro->headers[Header::SURROGATE_KEY] = 'a  b';
        $this->ro->headers[Header::ETAG] = '"1"';

        $this->assertTrue($this->storage->saveValue($this->ro, 60));
        $etag = $this->ro->headers[Header::ETAG];
        $this->assertTrue($this->storage->hasEtag($etag));

        $this->storage->invalidateTags(['b']);

        $this->assertNull($this->storage->get($this->ro->uri));
        $this->assertFalse($this->storage->hasEtag($etag));
    }

    public function testADonutTemplateUnderAnEmptyKeyIsStoredUnderTheRemainingKeys(): void
    {
        $donut = ResourceDonut::create($this->ro, new DonutRenderer(), new SurrogateKeys(new Uri('app://self/')), null, false);

        $this->storage->saveDonut($this->ro->uri, $donut, null, ['a', '', 'b']);
        $this->assertInstanceOf(ResourceDonut::class, $this->storage->getDonut($this->ro->uri));

        $this->storage->invalidateTags(['b']);

        $this->assertNull($this->storage->getDonut($this->ro->uri));
    }

    public function testInvalidateTagsWithNullPurgerLogsCdnSkipped(): void
    {
        $logger = new RecordingSemanticLogger();
        $storage = self::getResourceStorageInstance($logger);

        $storage->invalidateTags(['_user_']);

        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertTrue($context->roPoolInvalidated);
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
        // silently leave stale CDN content. The local pool is invalidated first and the
        // outcome is logged as cdn=failed before the exception surfaces.
        try {
            $storage->invalidateTags(['_user_']);
            $this->fail('Expected the purger failure to propagate (fail-closed)');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('purge failed', (string) $e->getPrevious()?->getMessage());
        }

        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertTrue($context->roPoolInvalidated);
        $this->assertSame('failed', $context->cdnStatus);
        $this->assertSame('failed', $context->jsonSerialize()['cdn']);
    }

    public function testHasEtagAcceptsIfNoneMatchVariants(): void
    {
        $this->ro->headers[Header::ETAG] = '"123456"';
        $this->storage->saveValue($this->ro, 10);
        $tag = trim($this->ro->headers[Header::ETAG], '"');

        $this->assertTrue($this->storage->hasEtag('"' . $tag . '"'), 'quoted entity-tag');
        $this->assertTrue($this->storage->hasEtag($tag), 'bare legacy token');
        $this->assertTrue($this->storage->hasEtag('W/"' . $tag . '"'), 'weak validator');
        $this->assertTrue($this->storage->hasEtag('"999999", "' . $tag . '"'), 'entity-tag list');
        $this->assertFalse($this->storage->hasEtag('"999999"'), 'unknown entity-tag');
    }

    public function testHasEtagSplitsOnlyOnCommasOutsideQuotes(): void
    {
        $this->ro->headers[Header::ETAG] = '"foo,bar"';
        $this->storage->saveValue($this->ro, 10);
        $tag = trim($this->ro->headers[Header::ETAG], '"');

        $this->assertTrue($this->storage->hasEtag('"' . $tag . '"'), 'comma inside a quoted opaque-tag is data');
        $this->assertTrue($this->storage->hasEtag('W/"' . $tag . '"'), 'weak validator with comma in opaque-tag');
        $this->assertTrue($this->storage->hasEtag('"999999", "' . $tag . '"'), 'list with a comma-bearing entity-tag');
        $this->assertFalse($this->storage->hasEtag('"bar"'), 'naive split fragment must not match');
        $this->assertFalse(
            $this->storage->hasEtag($tag),
            'unquoted legacy value is indistinguishable from a two-element list (documented limitation)',
        );
    }

    public function testHasEtagRejectsMalformedFieldValues(): void
    {
        $this->ro->headers[Header::ETAG] = '"123456"';
        $this->storage->saveValue($this->ro, 10);
        $tag = trim($this->ro->headers[Header::ETAG], '"');
        $this->assertTrue($this->storage->hasEtag('"' . $tag . '"'));

        $this->assertFalse($this->storage->hasEtag('"' . $tag), 'unterminated quoted entity-tag');
        $this->assertFalse($this->storage->hasEtag('"' . $tag . '" trailing'), 'trailing data after entity-tag');
        $this->assertFalse($this->storage->hasEtag('x"' . $tag . '"'), 'leading data before entity-tag');
        $this->assertFalse($this->storage->hasEtag('"' . $tag . '" "' . $tag . '"'), 'missing comma separator');
        $this->assertFalse($this->storage->hasEtag('"' . $tag . "\"\n"), 'trailing newline');
    }

    public function testAValueNotShapedLikeAStoredValidatorIsNotLookedUp(): void
    {
        // Every read of this pool throws, so a false here is an answer given without one.
        $storage = self::getResourceStorageInstance(roPool: new TagAwareAdapter(new FakeErrorCache()));

        foreach (['"日本.語"', '"' . str_repeat('b', 1000) . '.x"', '".x"', '"abc.x"', '"ro-abc.x"', '"no-dot"'] as $etag) {
            $this->assertFalse($storage->hasEtag($etag), $etag);
            $this->assertFalse($storage->hasEtagFor($etag, $this->ro->uri), $etag);
        }
    }

    public function testAnotherVariantsValidatorIsNotAnsweredWhereThisVariantHasNoEntry(): void
    {
        $_SERVER['X_VARY'] = 'lang';
        $_SERVER['X_LANG'] = 'en';
        try {
            $storage = self::getResourceStorageInstance(roPool: new TagAwareAdapter(new ArrayAdapter()));
            $this->ro->headers[Header::ETAG] = '"1"';
            $storage->saveValue($this->ro, 10);
            $etag = $this->ro->headers[Header::ETAG];
            $this->assertTrue($storage->hasEtag($etag));
            $this->assertTrue($storage->hasEtagFor($etag, $this->ro->uri));

            $_SERVER['X_LANG'] = 'ja';

            $this->assertNull($storage->get($this->ro->uri));
            $this->assertFalse($storage->hasEtag($etag), 'the entry asked about is the one this request is served from');
            $this->assertFalse($storage->hasEtagFor($etag, $this->ro->uri));
        } finally {
            unset($_SERVER['X_VARY'], $_SERVER['X_LANG']);
        }
    }

    public function testDeleteEtagDropsTheEntryThatHoldsTheEtag(): void
    {
        $this->ro->headers[Header::ETAG] = '"123456"';
        $this->storage->saveValue($this->ro, 10);
        $etag = $this->ro->headers[Header::ETAG];
        $this->assertTrue($this->storage->hasEtag($etag));

        $this->assertTrue($this->storage->deleteEtag($this->ro->uri));

        $this->assertFalse($this->storage->hasEtag($etag));
        $this->assertNull($this->storage->get($this->ro->uri));
    }

    public function testSaveEtagStoresNothing(): void
    {
        $items = new ArrayAdapter();
        $storage = self::getResourceStorageInstance(roPool: new TagAwareAdapter($items));

        $storage->saveEtag($this->ro->uri, '"123456"', 'a b', 10);

        $this->assertSame([], $items->getValues());
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
            new ResourceStorageSaver(),
            new CacheIdentityFactory(new GlobalServerContext()),
            $poolProvider,
            new CacheTags(new UriTag(), new BaseUriTag(new UriTag())),
        );

        $saved = $storage->saveValue($this->ro, 10);

        $this->assertFalse($saved, 'the store result surfaces to the caller');
        $context = $logger->events[0];
        assert($context instanceof SaveValueContext);
        $this->assertFalse($context->saved, 'the log records that the entry is NOT cached despite the save event');
        $this->assertSame(10, $context->requestedTtl);
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
            new ResourceStorageSaver(),
            new CacheIdentityFactory(new GlobalServerContext()),
            $poolProvider,
            new CacheTags(new UriTag(), new BaseUriTag(new UriTag())),
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
        $storage->saveDonutView($this->ro, -10);
        $storage->saveDonut($this->ro->uri, $donut, -10, []);

        $this->assertCount(4, $logger->events);
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
            || $event instanceof SaveDonutViewContext
            || $event instanceof SaveDonutContext,
        );

        return $event->requestedTtl;
    }

    public function testInvalidateTagsFailsWhenThePoolRefuses(): void
    {
        $logger = new RecordingSemanticLogger();
        $refusing = new FakeRefusingPool(new TagAwareAdapter(new ArrayAdapter()), refuseSave: false, refuseInvalidation: true);
        $storage = self::getResourceStorageInstance($logger, roPool: $refusing);

        $this->assertFalse($storage->invalidateTags(['_user_']));

        $context = $logger->events[0];
        assert($context instanceof InvalidateContext);
        $this->assertFalse($context->roPoolInvalidated);
        $this->assertSame(['tags', 'roPool', 'cdn', 'durationMs'], array_keys($context->jsonSerialize()));
        $this->assertSame('failed', $context->jsonSerialize()['roPool']);
    }
}
