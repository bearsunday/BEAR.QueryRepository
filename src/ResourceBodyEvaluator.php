<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;

use function assert;
use function is_array;

/**
 * Turns a resource body into the value tree the cache stores
 *
 * A body may still hold embedded requests when it reaches the storage boundary, so they
 * are materialized here — as copies, and without running a child that already ran.
 */
final class ResourceBodyEvaluator
{
    public function __invoke(mixed $body): mixed
    {
        if (! is_array($body)) {
            return $body;
        }

        /** @psalm-suppress MixedAssignment $item */
        foreach ($body as &$item) {
            $item = $this->copyForStore($item);
            if (! $item instanceof ResourceObject) {
                continue;
            }

            // Rewrite the copy, never the live object: its own body may embed more requests
            $item->body = $this($item->body);
        }

        return $body;
    }

    /**
     * Return the item as a copy the store may rewrite
     *
     * The rewrite below replaces embedded requests with their results, so whatever it
     * writes into must not be shared with the live response graph the renderer and the
     * transfer still read. `clone` is shallow, so every level needs its own copy: a
     * materialized parent shares the objects sitting inside its body with the memo.
     */
    private function copyForStore(mixed $item): mixed
    {
        if ($item instanceof RequestInterface) {
            return $this->materialize($item); // the memoized run, copied
        }

        if ($item instanceof ResourceObject) {
            return clone $item; // a live object placed in the body directly
        }

        return $item; // scalars, arrays and anything else pass through untouched
    }

    /**
     * Materialize an embedded request as a copy the live response graph cannot mutate
     *
     * The child was already run before the store (QueryRepository::setCacheDependency()
     * casts it, the renderer reads it), and AbstractRequest memoizes that single
     * execution. Its __invoke() ignores the memo and runs the request again, which for a
     * non-idempotent child would leave a type: "view" entry holding a body and a view
     * from two different runs. jsonSerialize() is the only public accessor for the memo,
     * hence the otherwise odd call here: it returns the memoized ResourceObject, or runs
     * the request once when nothing has read it yet.
     *
     * The copy is the storage boundary: the memoized object stays reachable through the
     * live request and the pool may hold it by reference, while the recursion above
     * rewrites body in place.
     */
    private function materialize(RequestInterface $request): ResourceObject
    {
        if ($request instanceof AbstractRequest) {
            return clone $this->asResourceObject($request->jsonSerialize());
        }

        return $this->asResourceObject($request());
    }

    /**
     * Narrow a materialized request result
     *
     * Both accessors are declared ResourceObject in some bear/resource releases and
     * mixed in others, so the type is asserted here instead of assumed at the call site.
     */
    private function asResourceObject(mixed $value): ResourceObject
    {
        assert($value instanceof ResourceObject);

        return $value;
    }
}
