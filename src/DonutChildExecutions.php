<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;
use WeakMap;

/** {@inheritDoc} */
final class DonutChildExecutions implements DonutChildExecutionsInterface
{
    /** @var WeakMap<ResourceObject, list<OnceRequest>> */
    private WeakMap $byPage;

    public function __construct()
    {
        // A fresh WeakMap is empty, so its template parameters are whatever the property
        // declares them as - never actually checked against `object`/`mixed`.
        /** @psalm-suppress PropertyTypeCoercion */
        $this->byPage = new WeakMap();
    }

    #[Override]
    public function register(ResourceObject $page, OnceRequest $once): void
    {
        $existing = $this->registered($page);
        $existing[] = $once;
        $this->byPage[$page] = $existing;
    }

    #[Override]
    public function take(ResourceObject $page): array
    {
        $registered = $this->registered($page);
        unset($this->byPage[$page]);

        return $registered;
    }

    /** @return list<OnceRequest> */
    private function registered(ResourceObject $page): array
    {
        if (! isset($this->byPage[$page])) {
            return [];
        }

        /** @var list<OnceRequest> $registered */
        $registered = $this->byPage[$page];

        return $registered;
    }
}
