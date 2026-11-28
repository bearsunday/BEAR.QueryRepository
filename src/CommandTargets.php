<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;

/**
 * The URIs a write method's #[Purge], #[Refresh] and #[Link(rel: 'collection')] declarations target
 *
 * @internal
 */
final readonly class CommandTargets
{
    /**
     * @param list<Uri> $purge   the #[Purge]/#[Refresh] URIs in declaration order, then the #[Link(rel: 'collection')] URIs
     * @param list<Uri> $refresh the #[Refresh] URIs to read again after every purge has run
     */
    public function __construct(
        public array $purge,
        public array $refresh,
    ) {
    }
}
