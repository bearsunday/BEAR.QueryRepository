<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\HttpCacheInject;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
#[Cacheable]
class Unmatch extends ResourceObject
{
    /*
     * for injection test
     */
    use HttpCacheInject;

    public function onGet($id, $unused)
    {
        return $this;
    }

    /**
     * @Purge(uri="app://self/user/friend?user_id={id}")
     */
    #[Purge(uri: 'app://self/user/friend?user_id={id}')]
    public function onPut(mixed $id, mixed $name, mixed $age)
    {
        return $this;
    }
}
