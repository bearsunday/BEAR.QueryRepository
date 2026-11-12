<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\InvokerInterface;
use BEAR\Resource\NullResourceObject;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use LogicException;
use Override;

/**
 * A request shaped like bear/async's AsyncRequest over a deferred request
 *
 * Its resource object is a stand-in that never runs. Its result comes from invoking it, and encoding it runs it
 * unless it has been invoked.
 */
final class FakeDeferredRequest extends AbstractRequest
{
    public int $runs = 0;
    private bool $invoked = false;

    public function __construct(private readonly ResourceObject $answer)
    {
        $standIn = new NullResourceObject();
        $standIn->uri = clone $answer->uri;
        parent::__construct(new class implements InvokerInterface {
            public function invoke(AbstractRequest $request): ResourceObject
            {
                throw new LogicException('the stand-in never runs');
            }
        }, $standIn);
    }

    /** {@inheritDoc} */
    #[Override]
    public function __invoke(array|null $query = null): ResourceObject
    {
        $this->runs++;
        $this->invoked = true;

        return $this->answer;
    }

    #[Override]
    public function jsonSerialize(): mixed
    {
        if (! $this->invoked) {
            $this->runs++;
        }

        return $this->answer->body;
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

    #[Override]
    public function toUri(): string
    {
        return (string) $this->answer->uri;
    }

    #[Override]
    public function toUriWithMethod(): string
    {
        return $this->toUri();
    }

    #[Override]
    public function linkSelf(string $linkKey): RequestInterface
    {
        return $this;
    }

    #[Override]
    public function linkNew(string $linkKey): RequestInterface
    {
        return $this;
    }

    #[Override]
    public function linkCrawl(string $linkKey): RequestInterface
    {
        return $this;
    }
}
