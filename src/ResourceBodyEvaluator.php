<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;

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
            if ($item instanceof RequestInterface) {
                $item = $this->materialize($item);
            }

            if ($item instanceof ResourceObject) {
                $item->body = $this($item->body);
            }
        }

        return $body;
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
            return clone $request->jsonSerialize();
        }

        return $request();
    }
}
