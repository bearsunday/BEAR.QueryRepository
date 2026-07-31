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

    public static function getResourceStorageInstance(): ResourceStorage
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
            new RepositoryLogger(),
            new NullPurger(),
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
