<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use BEAR\Sunday\Provide\Transfer\ConditionalResponseInterface;
use BEAR\Sunday\Provide\Transfer\Output;
use FakeVendor\HelloWorld\Resource\Page\Index;
use Koriym\SemanticLogger\NullSemanticLogger;
use Override;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

use function array_intersect_key;
use function array_shift;
use function array_splice;
use function explode;
use function ksort;
use function ob_get_clean;
use function ob_start;
use function sort;

use const PHP_EOL;

/**
 * A 304 says about caching what a 200 from the entry that validated the request would
 *
 * - its headers are that entry's, and only those a 304 is to repeat
 * - a request that did not validate leaves none, and so does a storage that cannot hand the entry over
 */
class NotModifiedHeadersTest extends TestCase
{
    private const SHARED = 'app://self/cacheable-with-shared-lifetime';

    private ResourceStorage $storage;
    private Uri $uri;
    private string $etag;

    protected function setUp(): void
    {
        $this->storage = ResourceStorageTest::getResourceStorageInstance(roPool: new TagAwareAdapter(new ArrayAdapter()));
        $this->uri = new Uri('page://self/not-modified');
        $this->etag = $this->save($this->uri, [
            Header::CACHE_CONTROL => 'max-age=60',
            'Vary' => 'Accept-Language',
            'Content-Type' => 'text/plain',
            Header::LAST_MODIFIED => 'Thu, 12 Nov 2026 00:00:00 GMT',
            Header::SURROGATE_KEY => 'a b',
            'X-Custom' => '1',
        ]);
    }

    public function testA304CarriesTheValidatorAndTheCacheControlOfItsEntry(): void
    {
        $injector = $this->injector(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(HttpCacheInterface::class)->to(CliHttpCache::class);
            }
        });
        $resource = $injector->getInstance(ResourceInterface::class);
        $resource->get(self::SHARED);
        $hit = $resource->get(self::SHARED);
        $httpCache = $injector->getInstance(HttpCacheInterface::class);

        $this->assertTrue($httpCache->isNotModified([Header::HTTP_IF_NONE_MATCH => $hit->headers[Header::ETAG]]));

        ob_start();
        $httpCache->transfer();
        $lines = explode(PHP_EOL, (string) ob_get_clean());

        $this->assertSame('304 Not Modified', array_shift($lines));
        $this->assertSame(['', ''], array_splice($lines, -2), 'a blank line ends the headers');
        sort($lines);
        $this->assertSame([
            'Cache-Control: ' . $hit->headers[Header::CACHE_CONTROL],
            'ETag: ' . $hit->headers[Header::ETAG],
        ], $lines);
    }

    public function testOnlyTheHeadersA304RepeatsAreTaken(): void
    {
        $conditionalRequest = new ConditionalRequest($this->storage, new NullSemanticLogger());

        $this->assertTrue($conditionalRequest->isNotModified('"stale", W/' . $this->etag, []));
        $this->assertSame([
            Header::CACHE_CONTROL => 'max-age=60',
            Header::ETAG => $this->etag,
            'Vary' => 'Accept-Language',
        ], self::sorted($conditionalRequest->notModifiedHeaders()));
    }

    /** One instance answers every request of a worker: what it holds must not outlive the request */
    public function testTheHeadersAreTakenOnceAndOnlyForTheRequestJustValidated(): void
    {
        $conditionalRequest = new ConditionalRequest($this->storage, new NullSemanticLogger());

        $this->assertTrue($conditionalRequest->isNotModified($this->etag, []));
        $this->assertNotSame([], $conditionalRequest->notModifiedHeaders());
        $this->assertSame([], $conditionalRequest->notModifiedHeaders(), 'taken once');

        $this->assertTrue($conditionalRequest->isNotModified($this->etag, []));
        $this->assertFalse($conditionalRequest->isNotModified('"stale"', []));
        $this->assertSame([], $conditionalRequest->notModifiedHeaders(), 'the request before validated, this one did not');
    }

    public function testTheScopedAnswerCarriesTheEntryOfTheUriAskedFor(): void
    {
        $other = new Uri('page://self/other');
        $otherEtag = $this->save($other, [Header::CACHE_CONTROL => 'private, max-age=5']);
        $conditionalRequest = new ConditionalRequest($this->storage, new NullSemanticLogger());

        $this->assertTrue($conditionalRequest->isNotModifiedFor($other, $otherEtag, []));
        $this->assertSame([
            Header::CACHE_CONTROL => 'private, max-age=5',
            Header::ETAG => $otherEtag,
        ], self::sorted($conditionalRequest->notModifiedHeaders()));

        $this->assertFalse($conditionalRequest->isNotModifiedFor($other, $this->etag, []));
        $this->assertSame([], $conditionalRequest->notModifiedHeaders(), 'a live validator of another URI');
    }

    public function testAStorageThatCannotHandTheEntryOverAnswersABare304(): void
    {
        $storage = new FakeUnscopableStorage($this->storage);
        $conditionalRequest = new ConditionalRequest($storage, new NullSemanticLogger());

        $this->assertTrue($conditionalRequest->isNotModified($this->etag, []));
        $this->assertSame([], $conditionalRequest->notModifiedHeaders());

        $httpCache = new CliHttpCache($storage);
        $this->assertTrue($httpCache->isNotModifiedFor($this->uri, [Header::HTTP_IF_NONE_MATCH => $this->etag]));
        $this->expectOutputString('304 Not Modified' . PHP_EOL . PHP_EOL);
        $httpCache->transfer();
    }

    public function testTheApplicationChoosesWhatA304Repeats(): void
    {
        $injector = $this->injector(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(ConditionalResponseInterface::class)->toInstance(new class implements ConditionalResponseInterface {
                    /** @inheritDoc */
                    #[Override]
                    public function isModified(ResourceObject $ro, array $server): bool
                    {
                        return true;
                    }

                    /** @inheritDoc */
                    #[Override]
                    public function getOutput(array $headers): Output
                    {
                        return new Output(304, array_intersect_key($headers, [Header::CACHE_CONTROL => true]), '');
                    }
                });
            }
        });
        $ro = $injector->getInstance(ResourceInterface::class)->get(self::SHARED);
        $conditionalRequest = $injector->getInstance(ConditionalRequest::class);

        $this->assertTrue($conditionalRequest->isNotModified($ro->headers[Header::ETAG], []));
        $this->assertSame([Header::CACHE_CONTROL => $ro->headers[Header::CACHE_CONTROL]], $conditionalRequest->notModifiedHeaders());
    }

    private function injector(AbstractModule $override): Injector
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        $module->override($override);

        return new Injector($module, __DIR__ . '/tmp');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return string the validator the entry was stored with
     */
    private function save(Uri $uri, array $headers): string
    {
        $ro = new Index();
        $ro->uri = $uri;
        $ro->body = [];
        $ro->headers = [...$headers, Header::ETAG => '"live"'];
        $this->storage->saveValue($ro, 60);

        return $ro->headers[Header::ETAG];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function sorted(array $headers): array
    {
        ksort($headers);

        return $headers;
    }
}
