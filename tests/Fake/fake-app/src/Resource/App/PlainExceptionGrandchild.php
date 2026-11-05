<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\Resource\ResourceObject;
use Exception;

class PlainExceptionGrandchild extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        throw new Exception('grand boom');
    }
}
