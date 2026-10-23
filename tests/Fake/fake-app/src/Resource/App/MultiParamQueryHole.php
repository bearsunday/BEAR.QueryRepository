<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] hole with two required query parameters, embedded through #[Embed]'s own
 * URI-template expansion (issue #222 follow-up E2E coverage): the donut placeholder/view
 * mapping and a later ResourceDonut::refresh() must carry and re-resolve BOTH, not just a
 * single param.
 */
#[Cacheable(expirySecond: 60)]
class MultiParamQueryHole extends ResourceObject
{
    public function onGet(string $id, string $tag) : ResourceObject
    {
        $this->body = ['id' => $id, 'tag' => $tag];

        return $this;
    }
}
