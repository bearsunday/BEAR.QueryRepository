<?php

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * A #[CacheableResponse] page whose onPost does not share onGet's required parameter (#219)
 *
 * onGet needs `id`; onPost creates a new item and never carries one. DonutCommandInterceptor's
 * automatic refresh has no entry to target and has to be skipped, not thrown. onPut addresses
 * the same entity onGet does, so a parameter it omits is a real signature mismatch - that one
 * still throws (mirrors BehaviorTest::testUnMatchQuery on the value-cache side).
 */
#[CacheableResponse]
class MismatchedDonutWriter extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPost(string $title): static
    {
        $this->body = ['title' => $title];

        return $this;
    }

    public function onPut(string $title): static
    {
        $this->body = ['title' => $title];

        return $this;
    }
}
