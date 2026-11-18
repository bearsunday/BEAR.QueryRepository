<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** The body names the entity after the parameter that holds its ID, so the body value is the entity, not the ID */
#[Cacheable]
class NestedUser extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/item?id={user}')]
    public function onGet(string $user): static
    {
        $this->body = ['user' => ['id' => $user, 'name' => 'x']];

        return $this;
    }
}
