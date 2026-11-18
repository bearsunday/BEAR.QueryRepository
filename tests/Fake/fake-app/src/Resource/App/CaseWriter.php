<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

/** A #[Cacheable] writer whose handler is spelled onPUT, beside a helper named like a write */
#[Cacheable]
class CaseWriter extends ResourceObject
{
    public function onGet(int $id): static
    {
        $this->body = ['id' => $id];

        return $this;
    }

    public function onPUT(int $id): static
    {
        $this->onPostProcess();

        return $this;
    }

    public function onPostProcess(): void
    {
        $this->code = 204;
    }
}
