<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * A request-scoped mark that a per-user value made this response unstorable
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
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
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
}
