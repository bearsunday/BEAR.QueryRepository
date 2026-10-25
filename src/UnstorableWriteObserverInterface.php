<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Observes a write the package skipped because the response was unstorable (#222/#234)
 *
 * The package default (NullUnstorableWriteObserver) does nothing: a skipped write is already
 * recorded as a `put_skipped` semantic-log event, and the same `#[Cacheable]`/`#[DonutCache]`
 * page may legitimately render a form only for some users. `DevUnstorableGateModule` overrides
 * this with `ThrowingUnstorableWriteObserver`, which surfaces a skip the developer should see
 * as a thrown exception - once, after rendering completes, not mid-render.
 *
 * skipped() is called only at the sites that actually skip a store
 * (`QueryRepository::doPut()`, `DonutRepository::doPutStatic()`/`doPutDonut()`'s template
 * check/`refreshDonut()` when the donut is cacheable) - never at a header-only check. A hole
 * legitimately refusing its OWN write (`UncacheableMarkScopeInterface::isMarkedForCurrentScope()`
 * true while `isNested()` is also true - e.g. a CSRF form hole) is expected, not reported.
 *
 * raiseIfPending() is called once per top-level request, after the OUTERMOST cacheable GET
 * interceptor (`CacheInterceptor`/`AbstractDonutCacheInterceptor`,
 * `UncacheableMarkScopeInterface::enterInterceptor()`/`leaveInterceptor()`) returns NORMALLY - so a
 * thrown exception never corrupts a template mid-`__toString()`, it surfaces only after the
 * full response was correctly rendered.
 *
 * clearPending() is called instead, at that same point, when a genuine exception is already
 * propagating out of that same finally (the resource's own execution, a render, or the store
 * itself failed for a reason that is not this skip): raising there would REPLACE that
 * exception rather than let it travel, which is worse than the skip going unreported this one
 * time - the `put_skipped` semantic-log event already recorded it. Either way the pending
 * state is gone afterward, so a later, unrelated request sharing this same (SINGLETON)
 * instance never inherits it.
 */
interface UnstorableWriteObserverInterface
{
    public function skipped(ResourceObject $ro, string $reason): void;

    public function raiseIfPending(): void;

    /** Drop a pending skip without raising - see raiseIfPending()'s own docblock */
    public function clearPending(): void;
}
