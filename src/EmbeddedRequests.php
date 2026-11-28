<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Generator;
use IteratorAggregate;
use Override;

/**
 * The requests a resource body embeds
 *
 * Enumerates only immediate values of the array-cast body, in order and with duplicates.
 * Does not execute requests, descend into children, or consume Traversable bodies.
 *
 * @implements IteratorAggregate<int, AbstractRequest>
 * @internal
 */
final readonly class EmbeddedRequests implements IteratorAggregate
{
    private function __construct(
        private ResourceObject $ro,
    ) {
    }

    public static function of(ResourceObject $ro): self
    {
        return new self($ro);
    }

    /** @return Generator<int, AbstractRequest> */
    #[Override]
    public function getIterator(): Generator
    {
        /** @var mixed $body */
        foreach ((array) $this->ro->body as $body) {
            if ($body instanceof AbstractRequest) {
                yield $body;
            }
        }
    }
}
