<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\FakeSerializeThrowsException;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class SerializeThrowsPlainException extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body = ['obj' => new FakeSerializeThrowsException()];

        return $this;
    }
}
