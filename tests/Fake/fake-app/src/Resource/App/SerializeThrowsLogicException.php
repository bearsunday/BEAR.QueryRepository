<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\FakeSerializeThrowsLogicException;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class SerializeThrowsLogicException extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body = ['obj' => new FakeSerializeThrowsLogicException()];

        return $this;
    }
}
