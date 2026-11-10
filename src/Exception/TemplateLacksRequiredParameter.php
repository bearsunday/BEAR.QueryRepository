<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use function implode;
use function sprintf;

/**
 * Thrown before a write when its #[Purge]/#[Refresh] template lacks a required onGet() parameter of the resource it names
 *
 * Add the parameter to the template; its snake_case name also counts.
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
