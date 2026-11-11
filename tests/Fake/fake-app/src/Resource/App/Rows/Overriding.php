<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use Override;

/** Overrides the onGet() its parent declares on */
#[Cacheable]
class Overriding extends AbstractChannel
{
    #[Override]
    public function onGet(string $channelId): static
    {
        $this->body = ['channelId' => $channelId, 'overridden' => true];

        return $this;
    }
}
