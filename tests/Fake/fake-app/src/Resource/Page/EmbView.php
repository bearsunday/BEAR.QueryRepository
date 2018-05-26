<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable(type="view")
 */
class EmbView extends ResourceObject
{
    /**
     * @Embed(rel="time", src="page://self/none");
     */
    public function onGet() : ResourceObject
    {
        $this->body += [
            'num' => 1
        ];

        return $this;
    }
}
