<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\Resource\ResourceObject;

/** A #[CacheableResponse] page whose only onGet() parameter has a default. */
#[CacheableResponse]
class PagedStaticDonut extends ResourceObject
{
    public function onGet(int $page = 1)
    {
        $this->body = ['page' => $page, 'time' => \microtime(true)];

        return $this;
    }
}
