<?php

declare(strict_types=1);

namespace FakeVendor\HelloWorld\Resource\App;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

#[Cacheable]
class DeclaredDefaultsWriter extends ResourceObject
{
    /** @var array<string, string> */
    public $headers = ['Cache-Control' => 'public'];

    /** @var array<string, string> */
    public $body = ['kind' => 'declared'];

    public function __construct()
    {
        $this->headers['Vary'] = 'Accept';
        $this->body['from'] = 'constructor';
    }

    public function onGet(string $id): static
    {
        $this->body['id'] = $id;

        return $this;
    }

    public function onPut(string $id): static
    {
        $this->body = ['written' => $id];

        return $this;
    }
}
