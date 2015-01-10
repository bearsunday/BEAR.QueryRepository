<?php

namespace FakeVendor\HelloWorld\Resource\App\User;

use BEAR\RepositoryModule\Annotation\QueryRepository;
use BEAR\Resource\ResourceObject;

/**
 * @QueryRepository
 */
class Profile extends ResourceObject
{
    public static $requested = false;

    public function onGet($user_id)
    {
        static $cnt = 0;

        $this['user_id'] = $user_id;
        $this['time'] = microtime(true);
        $this['cnt'] = $cnt++;
        self::$requested = true;

        return $this;
    }
}
