<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;
use Ray\Di\Injector;
use Ray\Di\InjectorInterface;

class CacheTagsTest extends TestCase
{
    private InjectorInterface $injector;

    protected function setUp(): void
    {
        $this->injector = new Injector(ModuleFactory::getInstance('FakeVendor\HelloWorld'), __DIR__ . '/tmp');
    }

    public function testTagsReadHeaderOrExplicitOverride(): void
    {
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('app://self/child');
        $ro->headers[Header::SURROGATE_KEY] = ' data  data ';
        $uriTag = new UriTag(new CacheIdentityFactory(new GlobalServerContext()));
        $baseUriTag = new BaseUriTag($uriTag, $this->injector->getInstance(CacheIdentityFactory::class));
        $cacheTags = new CacheTags($uriTag, $baseUriTag, $this->injector->getInstance(CacheIdentityFactory::class));

        $this->assertSame(['_child_', 'grp-_child_', 'data'], $cacheTags->tags($ro)->toArray());
        $this->assertSame(['_child_', 'grp-_child_'], $cacheTags->tags($ro, new TagSet())->toArray());
    }
}
