<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App\Assisted;

use BEAR\Resource\Annotation\ResourceParam;
use BEAR\Resource\ResourceObject;

/** A writer with no cache declaration whose onGet() reads one argument through #[ResourceParam] */
class Writer extends ResourceObject
{
    public function onGet(string $id, #[ResourceParam(uri: 'app://self/user?id=1#name')] string $owner): static
    {
        $this->body = ['v' => $id . ':' . $owner];

        return $this;
    }

    public function onPut(string $id): static
    {
        $this->code = 204;

        return $this;
    }
}
