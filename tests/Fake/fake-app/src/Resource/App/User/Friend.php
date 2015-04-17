<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
class Friend extends ResourceObject
{
    public function onGet($user_id)
    {
        static $cnt = 0;

        $this['user_id'] = $user_id;
        $this['time'] = microtime(true);
        $this['cnt'] = $cnt++;

        return $this;
    }
}
