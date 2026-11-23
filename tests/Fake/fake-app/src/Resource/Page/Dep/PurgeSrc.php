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
 * Writer carrying #[Purge] on a class that stores nothing
 *
 * The targets:
 *  - its own URI: the write's own key, which the write purges anyway, so the two are
 *    one purge. An entry stored for this resource (here by a direct
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
