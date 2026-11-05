<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\FakeSerializeThrowsRuntimeException;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class SerializeThrowsRuntimeException extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body = ['obj' => new FakeSerializeThrowsRuntimeException()];

        return $this;
    }
}
