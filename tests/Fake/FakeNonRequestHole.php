<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * An AbstractRequest that is not a BEAR\Resource\Request, the way BEAR\Async\AsyncRequest is not
 *
 * Counts jsonSerialize() calls so a test can prove DonutRequest never calls it on such a hole
 * (AsyncRequest's flushes its pending batch). Declares `: ResourceObject`, which every supported
 * bear/resource release accepts.
 */
final class FakeNonRequestHole extends AbstractRequest
{
    public int $jsonSerializeCalls = 0;

    public function __construct(InvokerInterface $invoker, ResourceObject $resourceObject)
    {
        parent::__construct($invoker, $resourceObject);
    }

    #[Override]
    public function jsonSerialize(): ResourceObject
    {
        $this->jsonSerializeCalls++;

        return $this->resourceObject;
    }

    /** {@inheritDoc} */
    #[Override]
    public function withQuery(array $query): RequestInterface
    {
        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function addQuery(array $query): RequestInterface
    {
        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function toUri(): string
    {
        return (string) $this->resourceObject->uri;
    }

    /** {@inheritDoc} */
    #[Override]
    public function toUriWithMethod(): string
    {
        return $this->toUri();
    }

    /** {@inheritDoc} */
    #[Override]
    public function linkSelf(string $linkKey): RequestInterface
    {
        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function linkNew(string $linkKey): RequestInterface
    {
        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function linkCrawl(string $linkKey): RequestInterface
    {
        return $this;
    }
}
