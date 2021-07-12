<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

final class ResourceState
{
    /** @var int  */
    public $code;

    /** @var AbstractUri  */
    public $uri;

    /** @var array<string, string> */
    public $headers;

    /** @var mixed  */
    public $body;

    /** @var ?string */
    public $view;

    /**
     * @param mixed $body
     */
    public function __construct(ResourceObject $ro, $body, ?string $view)
    {
        $this->code = $ro->code;
        $this->uri = $ro->uri;
        $this->headers = $ro->headers;
        $this->view = $view;
        $this->body = $body;
    }
}
