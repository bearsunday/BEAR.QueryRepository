<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Module\HalModule;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\AbstractModule;
use Ray\Di\Injector;
use RuntimeException;

use function array_keys;

/**
 * A value is rendered when it is stored, and a renderer may rewrite the resource it renders
 *
 * - the response keeps the view and the headers of that rendering
 * - the entry, and whoever reads the body, get the headers and the body the resource returned
 */
class RenderedValueTest extends TestCase
{
    private const HAL_PARENT = 'page://self/hal/parent-resource';
    private const URI = 'page://self/rendered-value';
    private const RETURNED_HEADERS = ['Content-Type' => 'text/plain', 'X-Returned' => '1'];
    private const RETURNED_BODY = ['returned' => true];

    public function testAnEmbeddedChildStaysReadableUnderARendererThatMovesItOutOfTheBody(): void
    {
        $injector = $this->injector(new HalModule());
        $resource = $injector->getInstance(ResourceInterface::class);

        $miss = $resource->get(self::HAL_PARENT);

        $this->assertStringContainsString('"_embedded"', (string) $miss->view);
        $this->assertSame('application/hal+json', $miss->headers['Content-Type'] ?? null);
        $this->assertSame(['child' => 'hal'], self::embeddedChildBody($miss), 'miss');

        $state = $injector->getInstance(QueryRepositoryInterface::class)->get(new Uri(self::HAL_PARENT));
        $this->assertInstanceOf(ResourceState::class, $state);
        $this->assertSame(['parent', 'child'], array_keys((array) $state->body));
        $this->assertArrayNotHasKey('Content-Type', $state->headers);
        $this->assertNull($state->view);

        $this->assertSame(['child' => 'hal'], self::embeddedChildBody($resource->get(self::HAL_PARENT)), 'hit');
    }

    public function testTheEntryHoldsTheHeadersAndTheBodyTheResourceReturned(): void
    {
        $repository = $this->injector()->getInstance(QueryRepositoryInterface::class);
        $ro = self::resource();

        $this->assertTrue($repository->put($ro));

        $this->assertSame(FakeRewritingRenderer::VIEW, $ro->view);
        $this->assertSame([...self::RETURNED_HEADERS, ...FakeRewritingRenderer::HEADERS], self::unstamped($ro->headers));
        $this->assertSame(self::RETURNED_BODY, $ro->body);

        $state = $repository->get(new Uri(self::URI));
        $this->assertInstanceOf(ResourceState::class, $state);
        $this->assertSame(self::RETURNED_HEADERS, self::unstamped($state->headers));
        $this->assertSame(self::RETURNED_BODY, $state->body);
        $this->assertNull($state->view);
    }

    /** The view is already rendered, so a responder does not render again: its headers must not be lost */
    public function testTheResponseKeepsTheHeadersOfItsRenderingWhenTheWriteFails(): void
    {
        $repository = $this->injector(new class extends AbstractModule {
            protected function configure(): void
            {
                $this->bind(PurgerInterface::class)->toInstance(new FakeThrowingPurger());
            }
        })->getInstance(QueryRepositoryInterface::class);
        $repository->put(self::resource());
        $ro = self::resource();

        try {
            $repository->put($ro); // replaces the entry: the cleanup purges, and the purge fails
            $this->fail('the purge failure propagates');
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(FakeCdnPurgeFailed::class, $e->getPrevious() ?? $e);
        }

        $this->assertSame(FakeRewritingRenderer::VIEW, $ro->view);
        $this->assertSame([...self::RETURNED_HEADERS, ...FakeRewritingRenderer::HEADERS], self::unstamped($ro->headers));
        $this->assertSame(self::RETURNED_BODY, $ro->body);
    }

    private function injector(AbstractModule|null $override = null): Injector
    {
        $module = ModuleFactory::getInstance('FakeVendor\HelloWorld');
        if ($override instanceof AbstractModule) {
            $module->override($override);
        }

        return new Injector($module, __DIR__ . '/tmp');
    }

    private static function resource(): ResourceObject
    {
        $ro = new #[Cacheable]
        class extends ResourceObject {
        };
        $ro->uri = new Uri(self::URI);
        $ro->headers = self::RETURNED_HEADERS;
        $ro->body = self::RETURNED_BODY;
        $ro->setRenderer(new FakeRewritingRenderer());

        return $ro;
    }

    /** What a parent reading the embedded child gets: its request on a miss, its stored result on a hit */
    private static function embeddedChildBody(ResourceObject $ro): mixed
    {
        $child = ((array) $ro->body)['child'] ?? null;
        self::assertTrue($child instanceof AbstractRequest || $child instanceof ResourceObject, 'the embed key is in the body');

        return $child->body;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string> without what the store stamps on every response
     */
    private static function unstamped(array $headers): array
    {
        unset($headers[Header::ETAG], $headers[Header::LAST_MODIFIED], $headers[Header::CACHE_CONTROL], $headers[Header::SURROGATE_KEY], $headers[Header::AGE]);

        return $headers;
    }
}
