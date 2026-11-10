<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/** #[Cacheable], so CommandInterceptor handles the declarations */
#[Cacheable]
class CacheableFanOutWriter extends ResourceObject
{
    public function onGet(): static
    {
        return $this;
    }

    /** @param list<string> $id */
    #[Purge(uri: 'app://self/tag-page{?id*}')]
    public function onPost(array $id): static
    {
        TagPage::$version++;
        $this->body = ['id' => $id];

        return $this;
    }

    /** @param list<string> $id */
    #[Refresh(uri: 'app://self/tag-page{?id*}')]
    public function onPut(array $id): static
    {
        TagPage::$version++;
        $this->body = ['id' => $id];

        return $this;
    }
}
