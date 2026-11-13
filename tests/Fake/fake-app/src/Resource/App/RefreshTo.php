<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/** Refreshes the URI the write names; not #[Cacheable], so RefreshInterceptor handles the declaration */
class RefreshTo extends ResourceObject
{
    #[Refresh(uri: '{+uri}')]
    public function onPut(string $uri): static
    {
        unset($uri);

        return $this;
    }
}
