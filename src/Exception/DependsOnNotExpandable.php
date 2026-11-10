<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use function sprintf;

/**
 * Thrown while a resource is stored when one of its #[DependsOn] declarations cannot be expanded to a URI
 *
 * `source` is where the values were read: `<uri> onGet()` for a resource's onGet(), or the row class. `reason` is one of
 * `no '<variable>'` (no such parameter value or public property), `'<variable>' is not an onGet() parameter`,
 * `'<variable>' is <type>` (neither scalar, Stringable nor null) and `declared on a resource class` (declare it on onGet()).
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
