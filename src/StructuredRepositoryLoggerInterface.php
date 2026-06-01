<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * A repository logger that also exposes its entries as structured data
 *
 * Separated from RepositoryLoggerInterface so that adding structured accessors
 * does not break third-party RepositoryLoggerInterface implementations (BC).
 * Use this for structural assertions instead of substring-matching __toString().
 */
/**
 * @deprecated Since the SemanticLogger migration; structured logs are now the
 *   {@see \Koriym\SemanticLogger\LogJson} tree returned by SemanticLogger::flush().
 */
interface StructuredRepositoryLoggerInterface extends RepositoryLoggerInterface
{
    /**
     * Return all accumulated log entries in insertion order
     *
     * Each entry is the merged `['op' => ..., ...$context]` array passed to log().
     *
     * @return list<array<string, mixed>>
     */
    public function getLogs(): array;

    /**
     * Return the operation names of all log entries in insertion order
     *
     * Convenience accessor for asserting the sequence of cache operations.
     *
     * @return list<string>
     */
    public function getOps(): array;
}
