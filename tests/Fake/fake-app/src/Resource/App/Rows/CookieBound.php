<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use Ray\WebContextParam\Annotation\CookieParam;

/** A variable a cookie fills without a default, which the query cannot give back */
#[Cacheable]
class CookieBound extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(#[CookieParam('channel')] string $channelId): static
    {
        $this->body = ['channel' => $channelId];

        return $this;
    }
}
