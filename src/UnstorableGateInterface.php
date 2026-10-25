<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Whether a response is unstorable for this request
 *
 * The single gate QueryRepository, DonutRepository, HeaderSetter and the CDN header setter all
 * consult, so a response a producer marked - or one whose Cache-Control already says no-store
 * or private - never reaches the shared cache a `#[Cacheable]` or `#[CacheableResponse]`
 * resource would otherwise write to.
 *
 * Reads the resource's own `#[HttpCache]`/`#[NoHttpCache]` declaration to exempt a
 * Cache-Control header that still reads exactly that value - the attribute's own client-facing
 * directive, not a value a method or a render produced: `#[Cacheable]` combined with
 * `#[HttpCache(noStore: true)]` still stores server-side while telling the client not to.
 *
 * reason() reads `UncacheableMarkScopeInterface::isMarked()`, "marked anywhere in this request" -
 * every check except `reasonExcludingHoleMarks()` and `reasonForOwnWrite()` uses it.
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
 */
interface UnstorableGateInterface
{
    /**
     * The reason this response must not be written to the shared cache, or null when storable
     *
     * @return 'unstorable'|null
     */
    public function reason(ResourceObject $ro): string|null;

    /**
     * The same check, but a mark made only inside an embedded hole's own execution
     * (UncacheableMarkScopeInterface::enterHole()/leaveHole()) does not trip it
     *
     * A `#[DonutCache]` template holds no hole content, only a placeholder for it, so only
     * what the page itself marked - directly, or while its own template renders - matters to
     * that write; DonutRepository::doPutDonut() is the only caller.
     *
     * @return 'unstorable'|null
     */
    public function reasonExcludingHoleMarks(ResourceObject $ro): string|null;

    /**
     * The same check, but ONLY for `QueryRepository::doPut()`'s own write (and `HeaderSetter`'s
     * header decision for that same write) - never blocked by an unrelated sibling resource's
     * own mark elsewhere in the request tree (#234), PROVIDED this write is actually running
     * inside its own `CacheInterceptor`-opened scope
     * (`UncacheableMarkScopeInterface::isCurrentWriteFor()`): then reads
     * `isMarkedForCurrentScope()` OR'd with `wasMarkedSinceCurrentWrite()` (see that
     * interface's docblock for why both halves are needed). A direct, non-AOP `put()` call -
     * no scope of its own, or nested inside a DIFFERENT resource's own write - falls back to
     * the conservative, any-owner `isMarked()` instead (1.17.0/pre-#234 behaviour): the only
     * safe answer when write-scope tracking has nothing to do with the write being judged.
     *
     * @return 'unstorable'|null
     */
    public function reasonForOwnWrite(ResourceObject $ro): string|null;
}
