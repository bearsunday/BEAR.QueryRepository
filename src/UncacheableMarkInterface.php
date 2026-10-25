<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * A request-scoped mark that a per-user value made this response unstorable
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
 *
 * A producer of a per-user value - a CSRF token issuer, or anything else that must never be
 * written to the shared resource cache - calls mark() at the point it produces that value, the
 * same way ASP.NET Core, Django, Symfony, Spring and Rails mark their response. The service
 * carries no knowledge of what marked it or why; it only answers whether this request was.
 *
 * enterHole()/leaveHole() push/pop an owner stack: DonutRequest opens a scope around each
 * embedded `#[DonutCache]`/`#[CacheableResponse]` hole's own execution, naming it by the
 * hole's own full, query-bearing URI (`RequestInterface::toUri()` - an `#[Embed]` request
 * always already carries its expanded query by the time DonutRequest sees it). mark()
 * attributes itself to whichever owner is current (the top of the stack, or the top-level
 * page when the stack is empty) - so a flat "inside any hole or not" flag is not enough: a
 * hole that is itself `#[DonutCache]` runs its own write while still nested inside its
 * embedder's scope, and a flag would misattribute the hole's own mark to "inside a hole"
 * from its own perspective too, letting its own template store the leak (issue #222).
 * isMarkedForCurrentScope() is what a `#[DonutCache]` write checks for its OWN template -
 * never a descendant hole's mark, which the stack keeps under that hole's own name - while
 * isMarked() (any owner, anywhere) is what every other decision (the live response headers at
 * every level, `#[CacheableResponse]`'s full-content store) checks, because rendered hole
 * content is substituted into the composing page's own view even when its stored template
 * is not affected. Only a genuinely identical URI (same path, same query) shares an owner
 * slot; anything else - including two sibling holes on the same path with different query
 * arguments - gets its own.
 *
 * Known limitation, not a regression: isMarked() answers "marked anywhere in this request, by
 * any owner" - every decision EXCEPT the following three uses it: a `#[DonutCache]` write's
 * own template (isMarkedForCurrentScope()), `QueryRepository::doPut()`'s own write when it
 * runs inside its own `CacheInterceptor`-opened scope (isMarkedForCurrentScope() ||
 * wasMarkedSinceCurrentWrite(), #234 - any-owner otherwise, see isCurrentWriteFor()) and
 * `HeaderSetter`'s private/no-store decision for that same write. Every other check stays
 * any-owner: `DonutRepositoryInterface` cannot see the resource call tree closely enough to
 * scope `doPutStatic()`/`doPutDonut()`'s response check or `refreshDonut()` safely (a
 * rescoped response leaks a nested donut's own hole content into the parent's stored entry).
 *
 * enterWrite()/leaveWrite() push/pop a write-scope stack, mirroring enterHole()/leaveHole(),
 * each frame tagged with the ResourceObject INSTANCE the write belongs to (object identity,
 * not its URI: two different instances can share a URI, and a URI string captured too early
 * would silently stop matching the moment that instance is replaced - see setWriteOwner()):
 * `CacheInterceptor` opens one, tagged with its own (pre-proceed) `$ro`, around its own
 * `proceed()` + the write it triggers (the donut interceptors do not - see
 * isCurrentWriteFor()'s own docblock for why identity, not a donut-side scope, is what makes a
 * nested write safe now). wasMarkedSinceCurrentWrite() answers "did ANY owner mark() since the
 * innermost open write's own enterWrite()" (a monotonic tick) - but is only trusted by #234's
 * own predicate (isCurrentWriteFor() true): a `#[Cacheable]` resource P itself, embedding an
 * independently donut-composed hole H that marks - H's mark is owned by H, never P, but
 * happened DURING P's own open write window (the #234 compound-leak rejected fix) - catches
 * what isMarkedForCurrentScope() alone cannot. The two checks are OR'd there because each
 * alone under-catches: the counter alone misses a mark made before the write window opened
 * (before the tree starts, or a descendant called synchronously right after an ancestor's own
 * mark, sharing its still-open scope); the scope check alone misses the compound leak above.
 *
 * setWriteOwner() re-binds the innermost open frame's own identity, mid-write, to a NEW
 * instance: `CacheInterceptor` must open its frame BEFORE `proceed()` (a mark made during
 * `proceed()` must land inside the window), using the pre-proceed `$ro` - but `proceed()`'s
 * return value, the instance actually passed to `put()`, is not always that same instance (an
 * `onGet()` that returns a freshly built ResourceObject rather than `$this`). Without
 * re-binding, isCurrentWriteFor() would silently stop matching for exactly that resource,
 * quietly falling back to any-owner and re-breaking #234 for it. setWriteOwner() keeps the
 * SAME frame (tick snapshot unchanged) but updates WHICH instance it belongs to, immediately
 * before the write it actually governs.
 *
 * A write with no frame of its own at all - a direct, non-AOP `QueryRepository::put()` call,
 * whether at the top level or nested inside another resource's own write (isCurrentWriteFor()
 * false either way, since the open frame, if any, belongs to a different instance) - falls
 * back to the conservative, any-owner isMarked() for that OWN-write decision too
 * (1.17.0/pre-#234 behaviour, not a regression): a direct put() has no known start of its own
 * content computation to snapshot a tick against, so any frame it could borrow would either
 * miss a mark made before the write actually happened (leak) or refuse past one that has
 * nothing to do with it (over-refuse, safe) - any-owner can only ever over-refuse, never leak.
 *
 * enterInterceptor()/leaveInterceptor() track nesting depth across every cacheable GET
 * interceptor, independent of the write-scope stack: a dev-mode write observer
 * (`DevUnstorableGateModule`) uses leaveInterceptor()'s `true` return (depth back to zero) to
 * report a skipped write once rendering completes, not mid-render - kept here, not in the
 * semantic logger, which is `NullSemanticLogger` by default and would never fire.
 *
 * isNested() lets that observer tell a hole legitimately refusing its OWN write (expected)
 * from a top-level resource marking itself with no hole to isolate it in (the #222 misuse a
 * developer needs to see).
 */
interface UncacheableMarkInterface
{
    /** Record that the current scope's response must not be stored in the shared cache */
    public function mark(string $reason): void;

    /** Was mark() called by any owner during this request? */
    public function isMarked(): bool;

    /** Was mark() called while the current scope (the innermost open hole, or the top-level page) was active? */
    public function isMarkedForCurrentScope(): bool;

    /** Push $uri as the current scope owner for the duration of that hole's own execution */
    public function enterHole(string $uri): void;

    /**
     * Close the innermost open scope owned by $uri, a no-op if $uri is not on the stack.
     * Truncates down to (and including) that occurrence rather than popping exactly one
     * entry: a caller that pushed its own scope and never left it (third-party misuse, or
     * code nested between a matching enterHole()/leaveHole() pair that leaks its own) must
     * not be able to permanently block this leaveHole() from ever closing - which a strict
     * "only pop if $uri is the exact top" check would, leaving every scope below the leak
     * open, and marks after it misattributed one level up, for the rest of the request.
     */
    public function leaveHole(string $uri): void;

    /** Whether a hole scope (enterHole()/leaveHole()) is currently open */
    public function isNested(): bool;

    /** Snapshot the current mark tick, tagged with the ResourceObject instance this write belongs to, as the innermost open write's own starting point */
    public function enterWrite(ResourceObject $ro): void;

    /** Re-bind the innermost open write scope's own identity to $ro, without touching its tick snapshot - see the class docblock's own setWriteOwner() paragraph */
    public function setWriteOwner(ResourceObject $ro): void;

    /** Close the innermost open write scope opened by enterWrite() */
    public function leaveWrite(): void;

    /** Did ANY owner mark() between the innermost open write's own enterWrite() and now? */
    public function wasMarkedSinceCurrentWrite(): bool;

    /**
     * Does the innermost open write scope belong to $ro (object identity, ===)? False when no
     * write is open, or the open write belongs to a DIFFERENT instance - a direct, non-AOP
     * put() for an unrelated resource (no scope of its own), or one nested inside another
     * resource's own write (a compound leak, #234/#222). #234's own-write-scoped predicate
     * (isMarkedForCurrentScope() || wasMarkedSinceCurrentWrite()) must only be trusted when
     * this is true; otherwise the write-scope stack has nothing to do with the write actually
     * being judged, and the conservative, any-owner isMarked() (1.17.0/pre-#234 behaviour) is
     * the only safe answer - it cannot under-refuse (leak), only over-refuse, which is
     * documented, accepted, pre-existing behaviour for a write outside its own scope.
     */
    public function isCurrentWriteFor(ResourceObject $ro): bool;

    /** Enter one more nested cacheable GET interceptor (CacheInterceptor/AbstractDonutCacheInterceptor) */
    public function enterInterceptor(): void;

    /** Leave one nested cacheable GET interceptor; true when this was the outermost one (depth back to zero) */
    public function leaveInterceptor(): bool;
}
