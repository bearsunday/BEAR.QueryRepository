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
 * This is the app-facing surface: the only method an application's own resource classes ever
 * need. The package's own write-scope mechanics (the hole stack, the write-scope stack, the
 * interceptor-depth counter - what decides whose write a mark actually refuses) live on
 * `UncacheableMarkScopeInterface` instead, a package-internal interface `UncacheableMark` also
 * implements, bound to the SAME request-scoped instance. They are kept off this interface
 * deliberately: application code that could call `enterWrite($myOwnRo)` could forge a
 * write-scope frame for its own direct `put()`, making an UNRELATED mark()'s refusal look
 * scoped away from it and storing a marked secret that should have been refused (security
 * review finding against an earlier draft of the #234 fix).
 */
interface UncacheableMarkInterface
{
    /** Record that the current scope's response must not be stored in the shared cache */
    public function mark(string $reason): void;
}
