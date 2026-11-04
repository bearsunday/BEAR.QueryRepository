<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: an embedded child its cached parent does not depend on.
 *
 * The child carries neither an ETag nor a Surrogate-Key; the reason is what its body showed at write time.
 * `no_body`: null - on a donut page usually a hole the renderer runs later (a 204 that ran reads the same).
 * `no_etag_or_surrogate_key`: set but untagged - usually a data dependency, whose change does not reach the parent's
 * stored entry (a hole whose class declares a default body reads the same).
 */
final class DependencySkippedContext extends AbstractContext
{
    public const TYPE = 'dependency_skipped';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/dependency_skipped.json';
    public const NO_BODY = 'no_body';
    public const NO_ETAG_OR_SURROGATE_KEY = 'no_etag_or_surrogate_key';

    public function __construct(
        public readonly string $parent,
        public readonly string $child,
        public readonly string $reason,
    ) {
    }

    public static function of(ResourceObject $parent, ResourceObject $child): self
    {
        return new self((string) $parent->uri, (string) $child->uri, $child->body === null ? self::NO_BODY : self::NO_ETAG_OR_SURROGATE_KEY);
    }
}
