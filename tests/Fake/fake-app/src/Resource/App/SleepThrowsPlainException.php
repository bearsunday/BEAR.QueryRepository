<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\QueryRepository\FakeSleepThrowsException;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class SleepThrowsPlainException extends ResourceObject
{
    public function onGet(): ResourceObject
    {
        $this->body = ['obj' => new FakeSleepThrowsException()];

        return $this;
    }
}
