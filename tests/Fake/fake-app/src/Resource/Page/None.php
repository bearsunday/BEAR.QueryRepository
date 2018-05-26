<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace FakeVendor\HelloWorld\Resource\Page;

use BEAR\Resource\ResourceObject;

class None extends ResourceObject
{
    public function onGet()
    {
        $this->body = [
            'time' => \microtime(true)
        ];
    }
}
