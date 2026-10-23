<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Whether a response is unstorable for this request
 *
 * The single gate QueryRepository, DonutRepository, HeaderSetter and the CDN header setter all
 * consult, so a response a producer marked - or one whose Cache-Control already says no-store
 * or private - never reaches the shared cache a `#[Cacheable]` or `#[CacheableResponse]`
 * resource would otherwise write to.
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
 */
interface UnstorableGateInterface
{
    /**
     * The reason this response must not be written to the shared cache, or null when storable
     *
     * @param string|null $declaredCacheControl the Cache-Control value `#[HttpCache]` already
     *     declares for this resource, read before `HeaderSetter` runs. A header that still
     *     reads exactly this value is the attribute's own client-facing directive, not a value
     *     the method or a render produced, and is exempt from the no-store/private check -
     *     `#[Cacheable]` combined with `#[HttpCache(noStore: true)]` still stores server-side
     *     while telling the client not to.
     *
     * @return 'unstorable'|null
     */
    public function reason(ResourceObject $ro, string|null $declaredCacheControl = null): string|null;
}
