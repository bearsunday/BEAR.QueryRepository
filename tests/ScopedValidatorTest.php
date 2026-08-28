<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use ReflectionProperty;

use function assert;

/**
 * Whose validator is this?
 *
 * `isNotModified()` receives the request environment and nothing else, so it can only ask whether
 * the offered validator is alive somewhere in the pool: a client or intermediary that returns a
 * validator it holds for another URI is told its copy of *this* URI is current. RFC 9110 §13.1.2
 * scopes If-None-Match to the selected representation of the target resource.
 *
 * Routing supplies the missing half, and routing is cheap - what a 304 avoids is running the
 * resource, not matching a path.
 */
class ScopedValidatorTest extends TestCase
{
    private ResourceInterface $resource;
    private HttpCacheInterface $httpCache;

    protected function setUp(): void
    {
        $injector = new Injector(new FakeEtagPoolModule(ModuleFactory::getInstance('FakeVendor\HelloWorld')), __DIR__ . '/tmp');
        $this->resource = $injector->getInstance(ResourceInterface::class);
        $this->httpCache = $injector->getInstance(HttpCacheInterface::class);

        parent::setUp();
    }

    /** @return array{first: string, second: string} */
    private function twoStoredValidators(): array
    {
        $first = $this->resource->get('app://self/user', ['id' => 1]);
        $second = $this->resource->get('app://self/user', ['id' => 2]);

        $this->assertArrayHasKey(Header::ETAG, $first->headers);
        $this->assertArrayHasKey(Header::ETAG, $second->headers);

        return ['first' => (string) $first->headers[Header::ETAG], 'second' => (string) $second->headers[Header::ETAG]];
    }

    public function testItsOwnValidatorRevalidates(): void
    {
        $etags = $this->twoStoredValidators();

        assert($this->httpCache instanceof UriScopedHttpCacheInterface);
        $answer = $this->httpCache->isNotModifiedFor(
            new Uri('app://self/user?id=1'),
            ['HTTP_IF_NONE_MATCH' => $etags['first']],
        );

        $this->assertTrue($answer, 'the validator this resource issued has to revalidate, or no client ever benefits');
    }

    public function testAnotherResourcesValidatorDoesNot(): void
    {
        $etags = $this->twoStoredValidators();

        assert($this->httpCache instanceof UriScopedHttpCacheInterface);
        $answer = $this->httpCache->isNotModifiedFor(
            new Uri('app://self/user?id=1'),
            ['HTTP_IF_NONE_MATCH' => $etags['second']],
        );

        $this->assertFalse($answer, 'a 304 here tells the client its copy of id=1 is current when the server never sent it');
    }

    public function testTheUnscopedAnswerStillTakesAnyLiveValidator(): void
    {
        // The framework-facing method is unchanged: it is what a pre-routing decision can ask, and
        // an application that has not moved its 304 check after routing keeps the old behaviour.
        $etags = $this->twoStoredValidators();

        $this->assertTrue($this->httpCache->isNotModified(['HTTP_IF_NONE_MATCH' => $etags['second']]));
    }

    public function testAQueryOrderDoesNotDecideOwnership(): void
    {
        // The stored value is the URI tag, which sorts the query: `?id=3&foo=bar` and
        // `?foo=bar&id=3` are one representation, and a client that reorders them is not a
        // different client.
        $ro = $this->resource->get('app://self/user', ['id' => 3, 'foo' => 'bar']);

        assert($this->httpCache instanceof UriScopedHttpCacheInterface);
        $this->assertTrue($this->httpCache->isNotModifiedFor(
            new Uri('app://self/user?foo=bar&id=3'),
            ['HTTP_IF_NONE_MATCH' => (string) $ro->headers[Header::ETAG]],
        ));
    }

    public function testAPlaceholderEntryFromAnOlderVersionAnswersFalseScoped(): void
    {
        // Upgrading writes new entries with the URI tag, but entries from before still carry the
        // constant 'etag' placeholder. The scoped answer must be false for those - one full
        // response per client after upgrade, once - while the unscoped answer stays true.
        $storage = ResourceStorageTest::getResourceStorageInstance();
        $user = $this->resource->get('app://self/user', ['id' => 5]);
        $etag = (string) $user->headers[Header::ETAG];
        $opaqueTag = trim($etag, '"');

        $refl = new ReflectionProperty($storage, 'etagPool');
        $pool = $refl->getValue($storage);
        $item = $pool->getItem($opaqueTag);
        $item->set('etag'); // pre-PR201 format: constant placeholder, no URI tag
        $pool->save($item);

        $this->assertTrue($storage->hasEtag($etag));
        $this->assertFalse($storage->hasEtagFor($etag, new Uri('app://self/user?id=5')));
    }
}
