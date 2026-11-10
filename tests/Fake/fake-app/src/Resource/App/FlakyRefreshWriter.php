<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

class FlakyRefreshWriter extends ResourceObject
{
    /** @param list<string> $id */
    #[Refresh(uri: 'app://self/flaky-tag-page{?id*}')]
    public function onPost(array $id): static
    {
        FlakyTagPage::$version++;
        $this->body = ['id' => $id];

        return $this;
    }
}
