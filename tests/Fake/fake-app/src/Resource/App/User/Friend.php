<?php

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\QueryRepository;
use BEAR\Resource\ResourceObject;

/**
 * @QueryRepository
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
