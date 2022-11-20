<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

/** @psalm-suppress MissingConstructor */
final class ResourceState
{
    /** @var int  */
    public $code = 200;

    /** @var AbstractUri */
    public $uri;

    /** @var array<string, string> */
    public $headers = [];

    /** @var mixed  */
    public $body;

    /** @var ?string */
    public $view;

    public static function create(ResourceObject $ro, mixed $body, string|null $view): self
    {
        $state = new self();
        $state->code = $ro->code;
        $state->uri = $ro->uri;
        $state->headers = $ro->headers;
        $state->view = $view;
        $state->body = $body;

        return $state;
    }

    public function visit(ResourceObject $ro): void
    {
        $ro->uri = $this->uri;
        $ro->code = $this->code;
        $ro->headers = $this->headers;
        $ro->body = $this->body;
        $ro->view = $this->view;
    }
}
