<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\Page\Html;

use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;

/** A page whose template holds about 50KB of real HTML around one cacheable hole */
#[DonutCache]
class ProbeLargeDonut extends ResourceObject
{
    #[Embed(rel: 'comment', src: 'page://self/html/comment')]
    public function onGet(): static
    {
        $this->body['article'] = '1';

        return $this;
    }
}
