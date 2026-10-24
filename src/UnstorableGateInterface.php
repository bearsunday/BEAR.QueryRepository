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
 * Reads the resource's own `#[HttpCache]`/`#[NoHttpCache]` declaration to exempt a
 * Cache-Control header that still reads exactly that value - the attribute's own client-facing
 * directive, not a value a method or a render produced: `#[Cacheable]` combined with
 * `#[HttpCache(noStore: true)]` still stores server-side while telling the client not to.
 *
 * reason() reads `UncacheableMarkInterface::isMarked()`, "marked anywhere in this request" -
 * see that interface's docblock for the resulting, deliberate limitation: a mark made by one
 * `#[DonutCache]` hole also refuses an entirely unrelated sibling resource's own write.
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
 */
interface UnstorableGateInterface
{
    /**
     * The reason this response must not be written to the shared cache, or null when storable
     *
     * @return 'unstorable'|null
     */
    public function reason(ResourceObject $ro): string|null;

    /**
     * The same check, but a mark made only inside an embedded hole's own execution
     * (UncacheableMarkInterface::enterHole()/leaveHole()) does not trip it
     *
     * A `#[DonutCache]` template holds no hole content, only a placeholder for it, so only
     * what the page itself marked - directly, or while its own template renders - matters to
     * that write; DonutRepository::doPutDonut() is the only caller.
     *
     * @return 'unstorable'|null
     */
    public function reasonExcludingHoleMarks(ResourceObject $ro): string|null;
}
