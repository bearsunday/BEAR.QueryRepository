<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use function implode;
use function sprintf;

/**
 * A #[Purge] or #[Refresh] template leaves out a required onGet() parameter of the resource it names
 *
 * Such a purge matches no entry, because a required parameter stays in every tag (see BaseUriTag),
 * and such a refresh fails on its GET after the write has run. The interceptors check the template
 * before the write, so the write does not run. Add the parameter to the template; a parameter written
 * in camelCase in onGet() may appear in snake_case.
 *
 * Message format: write method, declaration, missing parameters
 */
final class TemplateLacksRequiredParameter extends LogicException
{
    /** @param list<string> $missing */
    public function __construct(
        public readonly string $method,
        public readonly string $declaration,
        public readonly array $missing,
    ) {
        parent::__construct(sprintf('method %s, declaration %s, missing %s', $method, $declaration, implode(',', $missing)));
    }
}
