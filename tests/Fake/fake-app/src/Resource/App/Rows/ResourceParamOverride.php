<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use Override;

/** Overrides onGet() without #[ResourceParam], so channelId comes from the query */
#[Cacheable]
class ResourceParamOverride extends ResourceParamParent
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    #[Override]
    public function onGet(string $channelId = 'c9'): static
    {
        $this->body = ['channelId' => $channelId];

        return $this;
    }
}
