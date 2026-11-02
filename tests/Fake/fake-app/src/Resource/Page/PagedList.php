<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page with a default-valued onGet() parameter; onPost has no #[Purge]/#[Refresh]. */
#[CacheableResponse]
class PagedList extends ResourceObject
{
    public function onGet(int $page = 1)
    {
        $this->body = ['page' => $page, 'time' => \microtime(true)];

        return $this;
    }

    public function onPost(string $title)
    {
        return $this;
    }
}
