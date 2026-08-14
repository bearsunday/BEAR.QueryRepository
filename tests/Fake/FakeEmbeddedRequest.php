<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;

/**
 * An embedded request that is not an AbstractRequest
 *
 * bear/resource ships one implementation and it memoizes its execution, so this is the
 * only way to reach the interface-level path: a request with no memo to read.
 */
final class FakeEmbeddedRequest implements RequestInterface
{
    public int $invoked = 0;

    public function __invoke(array|null $query = null): ResourceObject
    {
        ++$this->invoked;

        $ro = new class extends ResourceObject{
        };
        $ro->body = ['n' => $this->invoked];

        return $ro;
    }

    public function withQuery(array $query): self
    {
    }

    public function addQuery(array $query): self
    {
    }

    public function toUri(): string
    {
    }

    public function toUriWithMethod(): string
    {
    }

    public function hash(): string
    {
    }

    public function request()
    {
    }

    public function linkSelf(string $linkKey): self
    {
    }

    public function linkNew(string $linkKey): self
    {
    }

    public function linkCrawl(string $linkKey): self
    {
    }
}
