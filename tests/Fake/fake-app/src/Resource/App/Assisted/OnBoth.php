<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** #[ResourceParam] on the method and on the parameter: BEAR.Resource binds the method's */
#[Cacheable]
class OnBoth extends ResourceObject
{
    #[ResourceParam(uri: 'app://self/user?id=1#name', param: 'owner')]
    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user?id=2#name', param: 'owner')] string $owner): static
    {
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }
}
