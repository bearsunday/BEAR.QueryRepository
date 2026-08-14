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
        $this->assertNull($donut->getUnchangedLastModified('tmpl'));
        $this->assertNull($donut->getRemainingStorageTtl());
        $this->assertSame([], $donut->getStorageTags());
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
