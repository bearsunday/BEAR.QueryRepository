<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\Resource\ResourceObject;

class PathTemplateWriter extends ResourceObject
{
    public static int $writes = 0;

    #[Purge(uri: 'app://self/{target}')]
    public function onPut(string $target): static
    {
        self::$writes++;
        $this->body = ['target' => $target];

        return $this;
    }
}
