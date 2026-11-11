<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceInterface;
use BEAR\Resource\Uri;
use FakeVendor\HelloWorld\Resource\App\Code;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;

class EtagSetterTest extends TestCase
{
    private ResourceInterface $resource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resource = (new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp'))->getInstance(ResourceInterface::class);
    }

    public function testStatusNotOk(): void
    {
        $setEtag = new EtagSetter();
        $ro = new Code();
        $ro->code = 500;
        $setEtag($ro);
        // $ro->headers[Header::ETAG]
        $this->assertArrayNotHasKey(Header::ETAG, $ro->headers);
    }

    public function testInvoke(): void
    {
        $ro = $this->resource->get('app://self/user', ['id' => 1]);
        $setEtag = new EtagSetter();
        $time = 0;
        $setEtag($ro, $time);
        $expect = 'Thu, 01 Jan 1970 00:00:00 GMT';
        $this->assertSame($expect, $ro->headers['Last-Modified']);
        // RFC 9110 §8.8.3: entity-tag is a DQUOTE-delimited opaque-tag
        $this->assertMatchesRegularExpression('/^"\d+"$/', $ro->headers[Header::ETAG]);
    }

    /** A child rebuilt in a later second carries a new Last-Modified; its parent's state did not change. */
    public function testEmbeddedChildHeadersDoNotChangeTheParentTag(): void
    {
        $setEtag = new EtagSetter();

        $this->assertSame(
            $setEtag->getEtagByEntireView($this->parentEmbedding('Thu, 01 Jan 1970 00:00:00 GMT', ['child-c' => 1])),
            $setEtag->getEtagByEntireView($this->parentEmbedding('Thu, 01 Jan 1970 00:00:02 GMT', ['child-c' => 1])),
        );
    }

    public function testEmbeddedChildBodyChangesTheParentTag(): void
    {
        $setEtag = new EtagSetter();

        $this->assertNotSame(
            $setEtag->getEtagByEntireView($this->parentEmbedding('Thu, 01 Jan 1970 00:00:00 GMT', ['child-c' => 1])),
            $setEtag->getEtagByEntireView($this->parentEmbedding('Thu, 01 Jan 1970 00:00:00 GMT', ['child-c' => 2])),
        );
    }

    /** A child restored from the donut cache has no body: its view is all that tells two states apart. */
    public function testEmbeddedChildViewChangesTheParentTag(): void
    {
        $setEtag = new EtagSetter();
        $tagOf = static function (string $childView) use ($setEtag): string {
            $child = new Code();
            $child->uri = new Uri('app://self/child-c');
            $child->body = null;
            $child->view = $childView;
            $parent = new Code();
            $parent->body = ['parent-a' => 1, 'child' => $child];

            return $setEtag->getEtagByEntireView($parent);
        };

        $this->assertNotSame($tagOf('child-v1'), $tagOf('child-v2'));
    }

    /** The same child, once just built (body and view) and once restored from the donut cache (view alone). */
    public function testADonutChildRestoredWithoutItsBodyKeepsTheParentTag(): void
    {
        $setEtag = new EtagSetter();
        $tagOf = static function (mixed $childBody) use ($setEtag): string {
            $child = new Code();
            $child->uri = new Uri('app://self/child-c');
            $child->body = $childBody;
            $child->view = '{"child-c":1}';
            $parent = new Code();
            $parent->body = ['parent-a' => 1, 'child' => $child];

            return $setEtag->getEtagByEntireView($parent);
        };

        $this->assertSame($tagOf(['child-c' => 1]), $tagOf(null));
    }

    public function testAnArrayListingAChildsStateIsNotThatChild(): void
    {
        $setEtag = new EtagSetter();
        $embedding = $this->parentEmbedding('Thu, 01 Jan 1970 00:00:00 GMT', ['child-c' => 1]);
        $listing = new Code();
        $listing->body = ['parent-a' => 1, 'child' => [Code::class, $listing->code, ['child-c' => 1]]];

        $this->assertNotSame($setEtag->getEtagByEntireView($embedding), $setEtag->getEtagByEntireView($listing));
    }

    public function testHeadersOfAChildEmbeddedInAChildDoNotChangeTheParentTag(): void
    {
        $setEtag = new EtagSetter();
        $tagOf = function (string $grandchildLastModified) use ($setEtag): string {
            $parent = $this->parentEmbedding($grandchildLastModified, ['child-c' => 1]);
            $parent->uri = new Uri('app://self/parent-a');
            $top = new Code();
            $top->body = ['top' => 1, 'parent' => $parent];

            return $setEtag->getEtagByEntireView($top);
        };

        $this->assertSame($tagOf('Thu, 01 Jan 1970 00:00:00 GMT'), $tagOf('Thu, 01 Jan 1970 00:00:02 GMT'));
    }

    /** @param array<string, int> $childBody */
    private function parentEmbedding(string $childLastModified, array $childBody): Code
    {
        $child = new Code();
        $child->uri = new Uri('app://self/child-c');
        $child->body = $childBody;
        $child->headers[Header::LAST_MODIFIED] = $childLastModified;
        $parent = new Code();
        $parent->body = ['parent-a' => 1, 'child' => $child];

        return $parent;
    }
}
