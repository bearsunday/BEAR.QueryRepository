<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class OptionalParam extends ResourceObject
{
    public function onGet(int $id, int $page = 1, int $sort = 0)
    {
        $this->body = [
            'id' => $id,
            'page' => $page,
            'sort' => $sort,
            'time' => \microtime(true),
        ];

        return $this;
    }

    #[Purge(uri: 'app://self/optional-param?id={id}')]
    public function onPut(int $id)
    {
        return $this;
    }
}
