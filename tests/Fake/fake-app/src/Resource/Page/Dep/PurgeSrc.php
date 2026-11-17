<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page\Dep;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/**
 * Non-Cacheable writer carrying #[Purge], so RefreshInterceptor runs instead of CommandInterceptor
 *
 * The targets:
 *  - its own URI: the write's own key, which RefreshInterceptor invalidates anyway, so the two
 *    share one invalidation. An entry stored for this resource (here by a direct
 *    QueryRepository::put) is dropped by the write.
 *  - page://self/dep/level-two: level-one embeds it, so the purge cascades to the parent.
 */
class PurgeSrc extends ResourceObject
{
    public $body = ['purge-src' => 0];

    public function onGet(string $id)
    {
        $this->body = ['purge-src' => $id];

        return $this;
    }

    #[Purge(uri: 'page://self/dep/purge-src{?id}')]
    #[Purge(uri: 'page://self/dep/level-two')]
    public function onPut(string $id)
    {
        unset($id);

        return $this;
    }
}
