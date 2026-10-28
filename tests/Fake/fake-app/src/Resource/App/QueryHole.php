<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] hole whose own identity depends on a query parameter carried in its
 * `#[Embed]` src: the donut placeholder and view-map URI must carry that query string, or a
 * later ResourceDonut::refresh() re-fetches this hole's query-less default instead of the
 * value a composing page actually embedded.
 */
#[Cacheable(expirySecond: 60)]
class QueryHole extends ResourceObject
{
    public function onGet(string $token = ''): ResourceObject
    {
        $this->body = ['token' => $token];

        return $this;
    }
}
