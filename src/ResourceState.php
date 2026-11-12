<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\Request;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;

use function is_array;
use function time;

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

    /** @var int|null Unix time when this state was stored; null in entries saved before this field existed */
    public $storedAt;

    /** @var list<int|string> Body keys whose request the entry holds as its result */
    private array $requests = [];

    public static function create(ResourceObject $ro, mixed $body, string|null $view): self
    {
        $state = new self();
        $state->code = $ro->code;
        $state->uri = $ro->uri;
        $state->headers = $ro->headers;
        $state->view = $view;
        $state->body = $body;
        $state->storedAt = time();
        if (! is_array($ro->body) || ! is_array($body)) {
            return $state;
        }

        /** @psalm-suppress MixedAssignment */
        foreach ($ro->body as $key => $item) {
            if ($item instanceof RequestInterface && ($body[$key] ?? null) instanceof ResourceObject) {
                $state->requests[] = $key;
            }
        }

        return $state;
    }

    public function visit(ResourceObject $ro): void
    {
        $ro->uri = $this->uri;
        $ro->code = $this->code;
        $ro->headers = $this->headers;
        $ro->body = $this->returnedBody();
        $ro->view = $this->view;
    }

    /** A renderer tells an embedded request from a value: a hit hands it what the miss did */
    private function returnedBody(): mixed
    {
        $body = $this->body;
        if (! is_array($body)) {
            return $body;
        }

        foreach ($this->requests as $key) {
            /** @psalm-suppress MixedAssignment */
            $result = $body[$key] ?? null;
            if ($result instanceof ResourceObject) {
                $body[$key] = new Request(new StoredResult(), $result, query: $result->uri->query);
            }
        }

        return $body;
    }
}
