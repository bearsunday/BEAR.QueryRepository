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
 * same way ASP.NET Core, Django, Symfony, Spring and Rails mark their response; every resource
 * whose OWN output carries that value must call mark() itself, even when it got the value
 * indirectly (a shared service, or a value another resource already marked) - a mark covers
 * only the marking resource and whatever embeds it, never an unrelated sibling that reads the
 * same value. The service carries no knowledge of what marked it or why; it only answers
 * whether this request was.
 *
 * This is the app-facing surface: the only method an application's own resource classes ever
 * need. The package's own write-scope mechanics (the hole stack, the write-scope stack, the
 * interceptor-depth counter - what decides whose write a mark actually refuses) live on
 * `UncacheableMarkScopeInterface` instead, a SEPARATE, package-internal interface
 * `UncacheableMark` implements - never this one. This interface itself is resolved to
 * `UncacheableMarker`, a thin wrapper whose only method is `mark()` (delegating to an
 * injected `UncacheableMarkScopeInterface`) - keeping the internal methods off this interface
 * is enforced at RUNTIME, not just by static analysis: application code holding an
 * `UncacheableMarkInterface` has no `enterWrite()` to call even if it tried - there is no such
 * method on the object it was handed, a fatal `Error`. A single do-everything interface an
 * earlier draft of the #234 fix shipped let application code call `enterWrite($myOwnRo)` on
 * the very instance it was handed for `mark()`, forging a write-scope frame for its own
 * direct `put()` and making an UNRELATED mark()'s refusal look scoped away from it - storing
 * a marked secret that should have been refused (security review finding).
 */
interface UncacheableMarkInterface
{
    /** Record that the current scope's response must not be stored in the shared cache */
    public function mark(string $reason): void;
}
