<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

#[HttpCache(sMaxAge: 600)]
class ProbeCdnOnly extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/mutable-comment')]
    #[Embed(rel: 'plain', src: 'page://self/html/probe-plain-child')]
    #[DependsOn(uri: 'app://self/taxonomy?id={id}')]
    public function onGet(int $id = 1): static
    {
        $this->body['x'] = $id;

        return $this;
    }
}
