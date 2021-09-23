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

    /**
     * @param mixed $body
     */
    public static function create(ResourceObject $ro, $body, ?string $view): self
    {
        $state = new self();
        $state->code = $ro->code;
        $state->uri = $ro->uri;
        $state->headers = $ro->headers;
        $state->view = $view;
        $state->body = $body;

        return $state;
    }
}
