<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;

final class ResourceState
{
    /** @var AbstractUri|string */
    public $uri;

    /** @var string */
    public $code;

    /** @var array<string, string> */
    public $headers;

    /** @var mixed */
    public $body;

    /** @var ?string */
    public $view;

    /**
     * @param AbstractUri|string   $uri
     * @param array<string, mixed> $headers
     * @param mixed                $body
     * @param ?string              $view
     */
    public function __construct($uri, string $code, array $headers, $body, ?string $view)
    {
        $this->uri = $uri;
        $this->code = $code;
        $this->headers = $headers;
        $this->body = $body;
        $this->view = $view;
    }
}
