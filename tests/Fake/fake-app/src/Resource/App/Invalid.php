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

/**
 * @Cacheable
 */
#[Cacheable]
class Invalid extends ResourceObject
{
    public function onGet($id, $unused)
    {
    }

    #[Purge(uri: "app://self/user/friend?user_id={id}")]
    public function onPut(mixed $id, mixed $name, mixed $age)
    {
    }
}
