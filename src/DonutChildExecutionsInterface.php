<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Tracks every `OnceRequest` created for one page's own `#[Embed]`s (issue #226)
 *
 * `EmbedOnceInterceptor` registers each wrapper here as it creates it, keyed by the page
 * `ResourceObject` it was embedded into - before `onGet()` runs, so a wrapper is on record
 * even if `onGet()` later `unset()`s it from `$ro->body` (or overwrites the slot with a
 * plain value pulled out of it) and `ResourceDonut::create()`'s body walk can no longer find
 * it. `DonutRepository` reads the list back once the page's donut template has been built -
 * late enough that `OnceRequest::wasRendered()` is final for every wrapper that did become a
 * hole - to tag the template with whichever children executed but were not.
 *
 * Bound `Scope::SINGLETON`, one instance per DI container (per request in the usual
 * BEAR.Sunday bootstrap) - the same lifetime `UncacheableMark` already relies on. Keyed by
 * object identity in a `WeakMap`, not by URI: two sibling pages embedding the same child URI
 * must not share a slot, and an entry a `#[DonutCache]` write never reaches (a non-200
 * response, a cache-store failure) is simply never read back, not leaked - `WeakMap` drops it
 * with the page object itself.
 */
interface DonutChildExecutionsInterface
{
    /** Record that `$once` was created for one of `$page`'s own `#[Embed]`s */
    public function register(ResourceObject $page, OnceRequest $once): void;

    /**
     * Return and forget every wrapper registered for `$page`
     *
     * A page read exactly once: the second call for the same `$page` returns an empty list,
     * so a write path that (for whatever reason) reads twice cannot double-tag.
     *
     * @return list<OnceRequest>
     */
    public function take(ResourceObject $page): array;
}
