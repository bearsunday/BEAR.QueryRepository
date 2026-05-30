<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Renders RepositoryLogger entries (getLogs()) as text
 *
 * The NDJSON form (RepositoryLogger::__toString) stays the machine/schema
 * contract; a renderer is a human-/AI-oriented *view* over the same data.
 * Each rendering concern (dependency tree, compact timeline, close cascade)
 * is a separate strategy implementing this interface.
 */
interface RepositoryLogRendererInterface
{
    /** @param list<array<string, mixed>> $logs */
    public function render(array $logs): string;
}
