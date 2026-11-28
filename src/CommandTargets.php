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
     * @param list<Uri> $purge   all declared URIs to purge, in declaration order
     * @param list<Uri> $refresh the #[Refresh] URIs to read again after every purge has run
     */
    public function __construct(
        public array $purge,
        public array $refresh,
    ) {
    }
}
