<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

/**
 * Whether a response is unstorable for this request - see CHANGELOG/README for the design
 * rationale.
 *
 * The single gate QueryRepository, DonutRepository, HeaderSetter and the CDN header setter
 * all consult: a response a producer marked, or one whose Cache-Control already says
 * no-store or private, never reaches the shared cache. A resource's own
 * `#[HttpCache]`/`#[NoHttpCache]` declaration is exempt from that check.
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
     * The same check, but a mark made only inside an embedded hole's own execution does not
     * trip it - a `#[DonutCache]` template holds no hole content, only a placeholder, so
     * only what the page itself marked matters to that write.
     *
     * @return 'unstorable'|null
     */
    public function reasonExcludingHoleMarks(ResourceObject $ro): string|null;

    /**
     * The same check, but scoped to `QueryRepository::doPut()`'s own write only: an
     * unrelated sibling resource's own mark elsewhere in the request tree must not refuse
     * it. Falls back to the conservative, any-owner check when this write has no
     * write-scope frame of its own to trust - the only safe answer when scope tracking has
     * nothing to do with the write being judged.
     *
     * @return 'unstorable'|null
     */
    public function reasonForOwnWrite(ResourceObject $ro): string|null;

    /**
     * Whether reason() answered 'unstorable' because of a mark rather than the response's own Cache-Control
     *
     * Callers pass this to HeaderSetter with reason()'s result, so the header decision uses the same predicate as the store decision.
     */
    public function isMarkReason(ResourceObject $ro): bool;

    /**
     * Whether reasonForOwnWrite() answered 'unstorable' because of a mark rather than the response's own Cache-Control
     *
     * HeaderSetter replaces the Cache-Control with `private, no-store` only for a mark; it must get this from the
     * same predicate that decided the refusal, or an unrelated mark would override a resource's own header.
     */
    public function isMarkReasonForOwnWrite(ResourceObject $ro): bool;
}
