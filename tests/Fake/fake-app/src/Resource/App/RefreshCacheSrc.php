<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

class RefreshCacheSrc extends ResourceObject
{
    public function onGet($id)
    {
        return $this;
    }

    /**
     * @Refresh(uri="app://self/refresh-dest{?id}")
     *
     * @param mixed $id
     */
    public function onPut($id)
    {
        unset($id);

        return $this;
    }
}
