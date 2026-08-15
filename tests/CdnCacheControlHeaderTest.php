<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\AkamaiModule;
use BEAR\QueryRepository\Cdn\FastlyModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Madapaja\TwigModule\TwigModule;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;

use function assert;
use function dirname;

class CdnCacheControlHeaderTest extends TestCase
{
    use SemanticLogTreeTrait;

    public function testCdnCacheControl(): void
    {
        $module = $this->getModule();
        $injector =  new Injector($module, __DIR__ . '/tmp');
        /** @var ResourceInterface $resource */
        $resource = $injector->getInstance(ResourceInterface::class);
        $ro = $resource->get('page://self/html/blog-posting');
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $ro->headers);
        $this->assertSame($ro->headers[Header::CDN_CACHE_CONTROL], 'max-age=10 stale-while-revalidate=10');
        $repository = $injector->getInstance(QueryRepositoryInterface::class);
        $logger = $injector->getInstance(SemanticLoggerInterface::class);
        assert($repository->purge(new Uri('page://self/html/comment')));
        $logger->flush(); // drain the put and the purge: the tree below is the refresh alone

        $donutRo = $resource->get('page://self/html/blog-posting');
        $tree = $this->flushAndValidate($logger);
        $this->assertNotNull(self::eventContextJsonOf($tree, 'refresh_donut'), 'the page is recomposed from the cached donut');
        $this->assertArrayHasKey(Header::CDN_CACHE_CONTROL, $donutRo->headers, 'Even if it is made from donut, it should have a CDN header.');
        // The log records the literal header the CDN receives - including this setter's
        // default of 10, which no sMaxAge was requested for and which the put_donut
        // request fields alone therefore cannot reveal.
        $cdnHeaders = self::eventContextJsonOf($tree, 'cdn_headers');
        $this->assertNotNull($cdnHeaders, 'the refresh records what it told the CDN');
        $this->assertStringContainsString('"CDN-Cache-Control":"max-age=10 stale-while-revalidate=10"', $cdnHeaders);
        $this->assertStringContainsString('"blog-posting-page"', $cdnHeaders, 'the keys a purge must reach are recorded');
    }

    public function testFastlyModule(): void
    {
        $module = $this->getModule();
        $module->override(new FastlyModule('apiKey', 'serviceId'));
        $module->override(new FakeFastlyPurgeModule());
        $injector =  new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $ro = $resource->get('page://self/html/blog-posting');
        $this->assertArrayHasKey('Surrogate-Control', $ro->headers);
        $this->assertSame($ro->headers['Surrogate-Control'], 'max-age=31536000');
        $this->assertArrayHasKey('Surrogate-Key', $ro->headers);
        // Fastly's lifetime header and default are its own: the log records both verbatim.
        $cdnHeaders = self::eventContextJsonOf($this->flushAndValidate($injector->getInstance(SemanticLoggerInterface::class)), 'cdn_headers');
        $this->assertNotNull($cdnHeaders);
        $this->assertStringContainsString('"Surrogate-Control":"max-age=31536000"', $cdnHeaders);
    }

    public function testAkamaiModule(): void
    {
        $module = $this->getModule();
        $module->override(new AkamaiModule());
        $injector =  new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $ro = $resource->get('page://self/html/blog-posting');
        $this->assertArrayHasKey('Akamai-Cache-Control', $ro->headers);
        $this->assertArrayHasKey('Edge-Cache-Tag', $ro->headers);
        $this->assertSame($ro->headers['Akamai-Cache-Control'], 'max-age=31536000');
        // Akamai renames the key header: the log must index this response under
        // Edge-Cache-Tag, not claim a Surrogate-Key the response no longer carries.
        $cdnHeaders = self::eventContextJsonOf($this->flushAndValidate($injector->getInstance(SemanticLoggerInterface::class)), 'cdn_headers');
        $this->assertNotNull($cdnHeaders);
        $this->assertStringContainsString('"Akamai-Cache-Control":"max-age=31536000"', $cdnHeaders);
        $this->assertStringContainsString('"Edge-Cache-Tag"', $cdnHeaders);
        $this->assertStringNotContainsString('"Surrogate-Key"', $cdnHeaders);
        $this->assertStringContainsString('"blog-posting-page"', $cdnHeaders, 'the purge keys come from Edge-Cache-Tag');
    }

    public function testNullCdnCacheControlModule(): void
    {
        $module = $this->getModule();
        $module->override(new NullCdnCacheControlModule());
        $injector =  new Injector($module, __DIR__ . '/tmp');
        $resource = $injector->getInstance(ResourceInterface::class);
        $ro = $resource->get('page://self/html/blog-posting');
        $this->assertArrayNotHasKey('Surrogate-Control', $ro->headers);
        $this->assertArrayNotHasKey('Header::CDN_CACHE_CONTROL_HEADER', $ro->headers);
        // No lifetime header anywhere in the record: the reader sees that the CDN was
        // given nothing to cache by, not merely that one flavor's header is absent.
        $cdnHeaders = self::eventContextJsonOf($this->flushAndValidate($injector->getInstance(SemanticLoggerInterface::class)), 'cdn_headers');
        $this->assertNotNull($cdnHeaders);
        $this->assertStringNotContainsString('Cache-Control', $cdnHeaders);
        $this->assertStringNotContainsString('Surrogate-Control', $cdnHeaders);
        $this->assertStringContainsString('"Surrogate-Key"', $cdnHeaders, 'the purge keys are still recorded');
    }

    private function getModule(): AbstractModule
    {
        $namespace = 'FakeVendor\HelloWorld';
        $module = new FakeEtagPoolModule(ModuleFactory::getInstance($namespace));
        $module->override(new TwigModule([dirname(__DIR__) . '/tests/Fake/fake-app/var/templates']));

        return $module;
    }
}
