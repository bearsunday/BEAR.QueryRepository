<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use PHPUnit\Framework\TestCase;

class CacheTagsTest extends TestCase
{
    public function testTagsReadHeaderOrExplicitOverride(): void
    {
        $ro = new class extends ResourceObject {
        };
        $ro->uri = new Uri('app://self/child');
        $ro->headers[Header::SURROGATE_KEY] = ' data  data ';
        $uriTag = new UriTag();
        $baseUriTag = new BaseUriTag($uriTag);
        $cacheTags = new CacheTags($uriTag, $baseUriTag);

        $this->assertSame(['_child_', 'grp-_child_', 'data'], $cacheTags->tags($ro)->toArray());
        $this->assertSame(['_child_', 'grp-_child_'], $cacheTags->tags($ro, new TagSet())->toArray());
    }
}
