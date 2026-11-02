<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/**
 * A #[CacheableResponse] page with both a required and a default-valued onGet() parameter, and
 * NO #[Purge]/#[Refresh] on its write method: exercises DonutCommandInterceptor's auto-purge
 * keeping the required param while dropping the defaulted one (#255).
 */
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
