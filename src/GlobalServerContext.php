<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function is_string;

/**
 * The default ServerContextInterface for PHP-FPM (one request at a time per worker)
 *
 * $_SERVER is safe to read directly there; for concurrent processing (Swoole, RoadRunner),
 * use a request-scoped implementation instead.
 */
final class GlobalServerContext implements ServerContextInterface
{
    #[Override]
    public function get(string $key): string|null
    {
        if (! isset($_SERVER[$key])) {
            return null;
        }

        /** @var mixed $value */
        $value = $_SERVER[$key];

        return is_string($value) ? $value : null;
    }

    #[Override]
    public function has(string $key): bool
    {
        return isset($_SERVER[$key]);
    }
}
