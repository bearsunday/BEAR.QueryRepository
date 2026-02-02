<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Provides access to server context in a thread-safe manner
 *
 * This interface abstracts access to request context (like $_SERVER) to support
 * concurrent request processing in environments like Swoole, RoadRunner, and ReactPHP.
 *
 * For traditional PHP-FPM: Use GlobalServerContext (default)
 * For Swoole/RoadRunner: Implement with request-scoped context
 */
interface ServerContextInterface
{
    /**
     * Get a value from the server context
     *
     * @param string $key The key to retrieve (e.g., 'HTTP_USER_AGENT', 'X_VARY')
     *
     * @return string|null The value or null if not set
     */
    public function get(string $key): string|null;

    /**
     * Check if a key exists in the server context
     *
     * @param string $key The key to check
     */
    public function has(string $key): bool;
}
