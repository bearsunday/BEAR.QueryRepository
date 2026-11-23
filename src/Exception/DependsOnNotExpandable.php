<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use function sprintf;

/**
 * Thrown while a resource is stored when one of its #[DependsOn] declarations cannot be expanded to a URI
 *
 * `source` is `<uri> onGet()`, the stored resource's URI. `reason` is one of `no '<variable>'` (no such parameter value),
 * `'<variable>' is not an onGet() parameter` (nor a key of the row or the body), `'<variable>' is bound by #[ResourceParam]` (not a
 * key of the row or the body, and its value comes from another resource, not the query) and `'<variable>' is <type>` (neither
 * scalar, Stringable nor null; a list only under an exploded query variable).
 * Nothing is stored. A dependency the declaration cannot express is tagged by hand with UriTagInterface::fromAssoc().
 */
final class DependsOnNotExpandable extends LogicException
{
    public function __construct(
        public readonly string $source,
        public readonly string $declaration,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('source %s, declaration %s, %s', $source, $declaration, $reason));
    }
}
