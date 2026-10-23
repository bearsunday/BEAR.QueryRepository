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
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
 */
interface UncacheableMarkInterface
{
    /** Record that this request's response must not be stored in the shared cache */
    public function mark(string $reason): void;

    /** Was mark() called during this request? */
    public function isMarked(): bool;
}
