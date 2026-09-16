<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

/**
 * A #[Cacheable] collection whose onPost does not share onGet's required parameter
 *
 * onGet needs `id`; onPost creates a new item and never carries one. The automatic
 * same-URI refresh (RefreshSameCommand) has no entry to target and has to be skipped,
 * not thrown - the explicit #[Purge] below still has to run.
 */
#[Cacheable]
class MismatchedWriter extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    #[Purge(uri: 'app://self/refresh-dest?id=1')]
    public function onPost(string $title): static
    {
        $this->body = ['title' => $title];

        return $this;
    }
}
