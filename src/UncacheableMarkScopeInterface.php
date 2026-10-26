<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * @internal Package-internal state and write-scope/hole-scope mechanics backing
 * `UncacheableMarkInterface::mark()`'s refusal decisions - see CHANGELOG/README for the
 * design rationale.
 *
 * `UncacheableMark` is the sole implementation and declares only this interface.
 * Application code depends on `UncacheableMarkInterface` instead, resolved to
 * `UncacheableMarker`, whose only method is `mark()`, so it can never forge its own
 * write-scope frame to bypass a mark's refusal.
 */
interface UncacheableMarkScopeInterface
{
    /** Record that the current scope's response must not be stored in the shared cache - the underlying operation UncacheableMarker::mark() delegates to */
    public function mark(string $reason): void;

    /** Was mark() called by any owner during this request? */
    public function isMarked(): bool;

    /** Was mark() called while the current scope (the innermost open hole, or the top-level page) was active? */
    public function isMarkedForCurrentScope(): bool;

    /** Push $uri as the current scope owner for the duration of that hole's own execution */
    public function enterHole(string $uri): void;

    /**
     * Close the innermost open scope owned by $uri, a no-op if $uri is not on the stack.
     * Truncates down to (and including) that occurrence, not just one entry: a caller that
     * never closes its own scope must not permanently block this from ever closing instead.
     */
    public function leaveHole(string $uri): void;

    /** Whether a hole scope (enterHole()/leaveHole()) is currently open */
    public function isNested(): bool;

    /** Snapshot the current mark tick, tagged with the ResourceObject instance this write belongs to, as the innermost open write's own starting point */
    public function enterWrite(ResourceObject $ro): void;

    /** Re-bind the innermost open write scope's own identity to $ro, without touching its tick snapshot - needed when proceed()'s return value is not the same instance the frame opened with */
    public function setWriteOwner(ResourceObject $ro): void;

    /** Close the innermost open write scope opened by enterWrite() */
    public function leaveWrite(): void;

    /** Did ANY owner mark() between the innermost open write's own enterWrite() and now? */
    public function wasMarkedSinceCurrentWrite(): bool;

    /**
     * Does the innermost open write scope belong to $ro (object identity, ===)? Must be true
     * before the own-write-scoped predicate (isMarkedForCurrentScope() ||
     * wasMarkedSinceCurrentWrite()) is trusted; otherwise the write-scope stack has nothing
     * to do with the write being judged, and the conservative, any-owner isMarked() is the
     * only safe answer - it can only over-refuse, never leak.
     */
    public function isCurrentWriteFor(ResourceObject $ro): bool;

    /** Enter one more nested cacheable GET interceptor (CacheInterceptor/AbstractDonutCacheInterceptor) */
    public function enterInterceptor(): void;

    /** Leave one nested cacheable GET interceptor; true when this was the outermost one (depth back to zero) */
    public function leaveInterceptor(): bool;

    /**
     * Is any cacheable GET interceptor currently active (depth > 0)? A direct, non-AOP
     * `QueryRepository::put()` call reached with none active must report its own pending
     * dev-gate skip, since no interceptor's own `leaveInterceptor()` will ever reach depth
     * zero to report it instead.
     */
    public function isInterceptorActive(): bool;
}
