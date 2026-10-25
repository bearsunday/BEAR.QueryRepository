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
 * legitimately refusing its OWN write (`UncacheableMarkInterface::isMarkedForCurrentScope()`
 * true while `isNested()` is also true - e.g. a CSRF form hole) is expected, not reported.
 *
 * raiseIfPending() is called once per top-level request, after the OUTERMOST cacheable GET
 * interceptor (`CacheInterceptor`/`AbstractDonutCacheInterceptor`,
 * `UncacheableMarkInterface::enterInterceptor()`/`leaveInterceptor()`) returns - so a thrown
 * exception never corrupts a template mid-`__toString()`, it surfaces only after the full
 * response was correctly rendered.
 */
interface UnstorableWriteObserverInterface
{
    public function skipped(ResourceObject $ro, string $reason): void;

    public function raiseIfPending(): void;
}
