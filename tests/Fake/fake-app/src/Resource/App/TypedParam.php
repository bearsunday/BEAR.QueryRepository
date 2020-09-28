<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 */
class TypedParam extends ResourceObject
{
    public static $id;

    public function onGet(int $id) : ResourceObject
    {
        self::$id = $id;

        return $this;
    }

    /**
     * @Refresh(uri="app://self/typed-param{?id}")
     */
    public function onPut(int $id) : ResourceObject
    {
        return $this;
    }
}
