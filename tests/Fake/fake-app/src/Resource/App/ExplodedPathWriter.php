<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class ExplodedPathWriter extends ResourceObject
{
    /** @param list<string> $path */
    #[Purge(uri: 'app://self{/path*}?user_id=1')]
    public function onPost(array $path): static
    {
        $this->body = ['path' => $path];

        return $this;
    }
}
