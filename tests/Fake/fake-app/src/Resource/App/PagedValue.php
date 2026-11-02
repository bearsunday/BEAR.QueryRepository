<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] value resource with a default-valued onGet() parameter and NO #[Purge]/
 * #[Refresh] on its write method, so RefreshSameCommand's MatchQuery-driven auto-purge is the
 * only invalidation path exercised (#255: a write whose own URI carries `page` must still
 * invalidate every page variant, not only the one it matched).
 */
#[Cacheable]
class PagedValue extends ResourceObject
{
    public function onGet(int $page = 1)
    {
        $this->body = ['page' => $page, 'time' => \microtime(true)];

        return $this;
    }

    public function onPut()
    {
        return $this;
    }
}
