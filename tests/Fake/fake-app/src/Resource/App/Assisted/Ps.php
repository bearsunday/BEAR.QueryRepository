<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\QueryRepository\DonutRepositoryInterface;
use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** A reader that stores itself with putStatic() inside onGet(), with no cache attribute */
class Ps extends ResourceObject
{
    public function __construct(
        private readonly DonutRepositoryInterface $repository,
    ) {
    }

    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user?id=1#name')] string $owner): static
    {
        $this->body = ['v' => $id . ':' . $owner];
        $this->repository->putStatic($this);

        return $this;
    }
}
