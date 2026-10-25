<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
 * own template (isMarkedForCurrentScope()), `QueryRepository::doPut()`'s own write
 * (isMarkedForCurrentScope() || wasMarkedSinceCurrentWrite(), #234) and `HeaderSetter`'s
 * private/no-store decision for that same write. Every other check stays any-owner:
 * `DonutRepositoryInterface` cannot see the resource call tree closely enough to scope
 * `doPutStatic()`/`doPutDonut()`'s response check or `refreshDonut()` safely (a rescoped
 * response leaks a nested donut's own hole content into the parent's stored entry).
 *
 * enterWrite()/leaveWrite() push/pop a write-scope stack, mirroring enterHole()/leaveHole():
 * `CacheInterceptor`/the donut interceptors open one around their own `proceed()` + the write
 * it triggers. wasMarkedSinceCurrentWrite() answers "did ANY owner mark() since my own
 * enterWrite()" (a monotonic tick), catching what isMarkedForCurrentScope() alone cannot: a
 * `#[Cacheable]` page P embedding an independently donut-composed hole H that marks - H's
 * mark is owned by H, never P, but it happened DURING P's open write window (the #234
 * compound-leak rejected fix). The two checks are OR'd because each alone under-catches: the
 * counter alone misses a mark made before the write window opened (before the tree starts, or
 * a descendant called synchronously right after an ancestor's own mark, sharing its still-open
 * scope); the scope check alone misses the compound leak above.
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

    /** Snapshot the current mark tick as the innermost open write's own starting point */
    public function enterWrite(): void;

    /** Close the innermost open write scope opened by enterWrite() */
    public function leaveWrite(): void;

    /** Did ANY owner mark() between the innermost open write's own enterWrite() and now? */
    public function wasMarkedSinceCurrentWrite(): bool;

    /** Enter one more nested cacheable GET interceptor (CacheInterceptor/AbstractDonutCacheInterceptor) */
    public function enterInterceptor(): void;

    /** Leave one nested cacheable GET interceptor; true when this was the outermost one (depth back to zero) */
    public function leaveInterceptor(): bool;
}
