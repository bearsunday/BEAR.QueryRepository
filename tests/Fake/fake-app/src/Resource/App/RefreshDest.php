<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;

class RefreshDest extends ResourceObject
{
    public static $id = 0;

    public function onGet(string $id)
    {
        self::$id = $id;

        return $this;
    }
}
