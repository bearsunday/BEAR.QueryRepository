<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: an embedded child its cached parent does not depend on.
 *
 * A child that has a body is a dependency; the reason is what its body showed at write time.
 * `no_body`: null - on a donut page usually a hole the renderer runs later (a 204 that ran reads the same).
 */
final class DependencySkippedContext extends AbstractContext
{
    public const TYPE = 'dependency_skipped';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/dependency_skipped.json';
    public const NO_BODY = 'no_body';

    public function __construct(
        public readonly string $parent,
        public readonly string $child,
        public readonly string $reason,
    ) {
    }

    public static function of(ResourceObject $parent, ResourceObject $child): self
    {
        return new self((string) $parent->uri, (string) $child->uri, self::NO_BODY);
    }
}
