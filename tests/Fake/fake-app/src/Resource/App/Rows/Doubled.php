<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\QueryRepository\Header;
use BEAR\QueryRepository\UriTag;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** Names one URI twice by declaration and once more with fromAssoc(), beside a hand-written key */
#[Cacheable]
class Doubled extends ResourceObject
{
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelId}')]
    public function onGet(string $channelId): static
    {
        $this->headers[Header::SURROGATE_KEY] = 'hand-key ' . (new UriTag())->fromAssoc('app://self/rows/taxonomy?ulid={channelId}', [['channelId' => $channelId]]);
        $this->body = ['channelId' => $channelId];

        return $this;
    }
}
