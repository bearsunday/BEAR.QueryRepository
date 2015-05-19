<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
class Invalid extends ResourceObject
{
    public function onGet($id, $unused)
    {
        return null;
    }

    /**
     * @Purge(uri="app://self/user/friend?user_id={id}")
     */
    public function onPut($id, $name, $age)
    {
    }
}
