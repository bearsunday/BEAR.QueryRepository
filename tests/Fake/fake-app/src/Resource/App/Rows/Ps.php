<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\QueryRepository\DonutRepositoryInterface;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use FakeVendor\HelloWorld\Entity\Catalog;

/** A list that stores itself with putStatic() inside onGet(), with no cache attribute */
class Ps extends ResourceObject
{
    public function __construct(
        private readonly DonutRepositoryInterface $repository,
    ) {
    }

    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string $channelId): static
    {
        $this->body = ['entries' => Catalog::items($channelId), 'channelId' => $channelId];
        $this->repository->putStatic($this);

        return $this;
    }
}
