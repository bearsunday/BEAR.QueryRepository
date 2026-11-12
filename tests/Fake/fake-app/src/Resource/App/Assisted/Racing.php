<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\QueryRepository\QueryRepositoryInterface;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;

/** Purges the resource it read while it runs, as a write landing between the read and the store would */
#[Cacheable]
class Racing extends ResourceObject
{
    public function __construct(
        private readonly QueryRepositoryInterface $repository,
    ) {
    }

    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user?id=1#name', param: 'owner')] string $owner): static
    {
        $this->repository->purge(new Uri('app://self/user?id=1'));
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }
}
