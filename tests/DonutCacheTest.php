<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

use function dirname;
use function gmdate;
use function sprintf;
use function strlen;
use function time;
use function unserialize;

class DonutCacheTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $path = dirname(__DIR__) . '/tests/Fake/fake-app/var/templates';
        $module->override(new TwigModule([$path]));
        $injector = new Injector($module, __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);

        parent::setUp();
    }

    public function testGetState(): void
    {
        $headers = ['Content-Type' => 'application/xml;'];
        $donut = new ResourceDonut('cmt=[le:page://self/html/comment]', $headers, null, true);
        $blog = $this->resource->get('page://self/html/blog-posting');
        $ro = $donut->refresh($this->resource, $blog);
        $this->assertInstanceOf(ResourceObject::class, $ro);
        $this->assertSame('cmt=comment01', $ro->view);
        $this->assertSame($headers['Content-Type'], $ro->headers['Content-Type']);
    }

    public function testGetUnchangedLastModified(): void
    {
        $ro = $this->resource->get('page://self/html/comment');
        $view = $ro->toString();
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, 1000);
        $donut = (new ResourceDonut('cmt=[le:page://self/html/comment]', [], null, true))->withContentState($ro);

        // identical content carries over the recorded Last-Modified
        $this->assertSame(1000, $donut->getUnchangedLastModified($view));
        // changed content advances Last-Modified (null lets the caller use the current time)
        $this->assertNull($donut->getUnchangedLastModified('changed content'));
        // a donut without recorded content state (e.g. putDonut) never carries over
        $this->assertNull((new ResourceDonut('cmt=[le:page://self/html/comment]', [], null, false))->getUnchangedLastModified($view));
    }

    public function testContentStateUpdateKeepsLastModifiedMovingForward(): void
    {
        $ro = $this->resource->get('page://self/html/comment');
        $view = $ro->toString();
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, 1000);
        $donut = (new ResourceDonut('cmt=[le:page://self/html/comment]', [], null, true))->withContentState($ro);

        // A→B: the state recorded at refresh becomes the new comparison base
        $this->assertNull($donut->getUnchangedLastModified('changed content'));
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, 2000);
        $ro->view = 'changed content';
        $donut = $donut->withContentState($ro);

        // B→B: stable at the change time
        $this->assertSame(2000, $donut->getUnchangedLastModified('changed content'));
        // B→A: must not revive the older Last-Modified recorded for A
        $this->assertNull($donut->getUnchangedLastModified($view));
    }

    public function testLegacyDonutPayloadHasNoContentState(): void
    {
        // A payload serialized before lastModified/contentHash existed: the properties
        // are uninitialized, so no Last-Modified carry-over happens.
        $donut = $this->legacyDonut();

        $this->assertNull($donut->getUnchangedLastModified('tmpl'));
        $this->assertNull($donut->getRemainingStorageTtl());
        $this->assertSame([], $donut->getStorageTags());
    }

    public function testLegacyDonutPayloadCanRecordStorageState(): void
    {
        // A warm cache surviving a deploy: a re-saved legacy entry records the lifetime and
        // tags it is stored with, while the content state it never had stays absent.
        $donut = $this->legacyDonut()->withStorageState(100, ['fresh-tag']);

        $this->assertSame(['fresh-tag'], $donut->getStorageTags());
        $remaining = $donut->getRemainingStorageTtl();
        // Not just <= 100: null passes that comparison, so a regression that stopped
        // recording templateExpiresAt for a legacy payload would slip through.
        $this->assertIsInt($remaining);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(100, $remaining);
        $this->assertNull($donut->getUnchangedLastModified('tmpl'));
    }

    public function testLegacyDonutPayloadCanRecordContentState(): void
    {
        // The same entry refreshed: the recomposed content becomes the state the next
        // refresh compares against, and the lifetime it was stored with stays absent.
        $ro = new class extends ResourceObject{
        };
        $ro->view = 'recomposed';
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, 1000);

        $donut = $this->legacyDonut()->withContentState($ro);

        $this->assertSame(1000, $donut->getUnchangedLastModified('recomposed'));
        $this->assertNull($donut->getRemainingStorageTtl());
        $this->assertSame([], $donut->getStorageTags());
    }

    public function testZeroTtlRecordsNoTemplateExpiry(): void
    {
        // 0 does not mean "already lapsed": ResourceStorageSaver only calls expiresAfter()
        // for a positive value, so the entry is stored with no expiry and there is no
        // lifetime to carry. Recording time() here would report 0 remaining on the next
        // refresh and stop the content-state write of an entry that never expires.
        $donut = (new ResourceDonut('tmpl', [], null, true))->withStorageState(0, ['tag']);

        $this->assertNull($donut->getRemainingStorageTtl());
        $this->assertSame(['tag'], $donut->getStorageTags());
    }

    /** A donut as it was serialized before the content and storage state existed */
    private function legacyDonut(): ResourceDonut
    {
        $class = ResourceDonut::class;
        $prop = static fn (string $name): string => "\0{$class}\0{$name}";
        $payload = sprintf(
            'O:%d:"%s":4:{s:%d:"%s";s:4:"tmpl";s:%d:"%s";a:0:{}s:3:"ttl";N;s:10:"isCacheble";b:1;}',
            strlen($class),
            $class,
            strlen($prop('template')),
            $prop('template'),
            strlen($prop('headers')),
            $prop('headers'),
        );
        $donut = unserialize($payload);
        $this->assertInstanceOf(ResourceDonut::class, $donut);

        return $donut;
    }

    public function testStorageStatePreservesExplicitTtlAndTags(): void
    {
        $donut = (new ResourceDonut('tmpl', [], null, true))->withStorageState(100, ['original-tag']);
        $remainingTtl = $donut->getRemainingStorageTtl();

        $this->assertNotNull($remainingTtl);
        $this->assertGreaterThanOrEqual(99, $remainingTtl);
        $this->assertLessThanOrEqual(100, $remainingTtl);
        $this->assertSame(['original-tag'], $donut->getStorageTags());
    }

    public function testExpiringTemplateReportsNoRemainingStorageTtl(): void
    {
        // A template entry whose recorded lifetime has just lapsed: the state must not be
        // re-saved with a fresh one, so the remaining time reads 0 rather than negative.
        $donut = new ResourceDonut('tmpl', [], null, true, 1000, 'hash', time() - 5, ['original-tag']);

        $this->assertSame(0, $donut->getRemainingStorageTtl());
    }

    public function testStorageTagsFallBackToTheSurrogateKeyHeader(): void
    {
        // Donuts stored before the tags were recorded still carry them in the header.
        $donut = new ResourceDonut('tmpl', [Header::SURROGATE_KEY => 'page-key other-key'], null, true);

        $this->assertSame(['page-key', 'other-key'], $donut->getStorageTags());
    }
}
