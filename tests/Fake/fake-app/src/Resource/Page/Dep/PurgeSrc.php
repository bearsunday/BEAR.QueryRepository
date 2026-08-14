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
 * Both targets are only expressible on this path:
 *  - its own URI: no RefreshSameCommand runs for a non-Cacheable class, so an entry stored
 *    for this resource (here by a direct QueryRepository::put) survives the write unless the
 *    method purges itself explicitly. On a #[Cacheable] class the same annotation would be
 *    redundant, and would delete the entry RefreshSameCommand had just refreshed.
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
