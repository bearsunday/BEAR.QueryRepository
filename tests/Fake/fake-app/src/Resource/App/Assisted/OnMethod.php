<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** #[ResourceParam] on the method, naming the parameter */
#[Cacheable]
class OnMethod extends ResourceObject
{
    #[ResourceParam(uri: 'app://self/user?id=1#name', param: 'owner')]
    public function onGet(string $id, string $owner): static
    {
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }
}
