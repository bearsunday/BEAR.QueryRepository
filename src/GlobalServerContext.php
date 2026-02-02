<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function is_string;

/**
 * Server context implementation using $_SERVER superglobal
 *
 * This is the default implementation for traditional PHP-FPM environments
 * where each request runs in isolation and $_SERVER is safe to use.
 *
 * For concurrent request processing (Swoole, RoadRunner), use a request-scoped
 * implementation instead.
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
