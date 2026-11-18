<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Rows;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;

/** An article whose channel is known only once it is read: onGet() puts it in the body */
#[Cacheable]
class BodyDeclared extends ResourceObject
{
    private const CHANNELS = ['a1' => 'c1', 'a3' => 'c2'];

    #[DependsOn(uri: 'app://self/rows/taxonomy?ulid={channelUlid}')]
    #[DependsOn(uri: 'app://self/rows/article?ulid={articleId}')]
    public function onGet(string $articleId): static
    {
        $this->body = ['channelUlid' => self::CHANNELS[$articleId]];

        return $this;
    }
}
