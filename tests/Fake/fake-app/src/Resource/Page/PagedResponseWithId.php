<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page with a required and a default-valued onGet() parameter; onPut has no #[Purge]/#[Refresh]. */
#[CacheableResponse]
class PagedResponseWithId extends ResourceObject
{
    public function onGet(int $id, int $page = 1)
    {
        $this->body = ['id' => $id, 'page' => $page, 'time' => \microtime(true)];

        return $this;
    }

    public function onPut(int $id)
    {
        return $this;
    }
}
