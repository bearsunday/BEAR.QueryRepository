<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use LogicException;
use Override;

/**
 * Mimics BEAR\Async\AsyncRequest's one contract-breaking override, without depending on
 * bear/async itself: jsonSerialize() answers something other than the memoized
 * ResourceObject AbstractRequest's own contract promises (BEAR\Async\AsyncRequest decodes
 * an already-flushed JSON view into an array instead, to support its own PendingRequests
 * parallel-execution protocol). By default never actually invoked - a real AsyncRequest's
 * jsonSerialize() reads a result PendingRequests already flushed, not a fresh invoke() - so the
 * default inner invoker fails the test loudly if ResourceBodyEvaluator ever calls it. Pass an
 * invoker to exercise a caller that falls back to invoking it (DonutRequest).
 */
final class FakeAsyncLikeRequest extends AbstractRequest
{
    public int $jsonSerializeCalls = 0;

    public function __construct(ResourceObject $resourceObject, InvokerInterface|null $invoker = null)
    {
        parent::__construct(
            $invoker ?? new class implements InvokerInterface {
                public function invoke(AbstractRequest $request): ResourceObject
                {
                    throw new LogicException('ResourceBodyEvaluator must not invoke an AsyncRequest-shaped request');
                }
            },
            $resourceObject,
        );
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        $this->jsonSerializeCalls++;

        return ['decoded' => 'from-pending-requests'];
    }

    /**
     * Never exercised by ResourceBodyEvaluator (it reads jsonSerialize() only) - stubbed
     * out just to satisfy AbstractRequest's abstract contract for a concrete subclass.
     */
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
        return (string) $this->uri;
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
