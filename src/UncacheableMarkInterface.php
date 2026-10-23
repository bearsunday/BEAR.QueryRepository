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
 * enterHole()/leaveHole() attribute a mark() made while they are open to an embedded
 * `#[DonutCache]`/`#[CacheableResponse]` hole's own execution, not to the composing page -
 * DonutRequest opens the scope around each hole it resolves. A `#[DonutCache]` template holds
 * only a placeholder for a hole, never its content, so a hole marking itself (its own storage
 * is separately gated) must not block the page's own template write; isMarkedOutsideHole() is
 * what that write checks instead of isMarked().
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
 */
interface UncacheableMarkInterface
{
    /** Record that this request's response must not be stored in the shared cache */
    public function mark(string $reason): void;

    /** Was mark() called during this request, inside a hole scope or not? */
    public function isMarked(): bool;

    /** Was mark() called outside every enterHole()/leaveHole() scope during this request? */
    public function isMarkedOutsideHole(): bool;

    /** Open a scope attributing a mark() made during it to an embedded hole */
    public function enterHole(): void;

    /** Close the innermost open hole scope */
    public function leaveHole(): void;
}
