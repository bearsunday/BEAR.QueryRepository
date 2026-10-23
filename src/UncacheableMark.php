<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

use function array_key_last;
use function array_slice;

/**
 * {@inheritDoc}
 *
 * Bound Scope::SINGLETON (see QueryRepositoryModule): one instance per DI container, which is
 * one per request in the usual BEAR.Sunday bootstrap - the same lifetime ResourceStorage and
 * QueryRepository already rely on. A long-running worker (Swoole, RoadRunner) that reuses one
 * container across requests must rebind this per request, the same way it already must for
 * ServerContextInterface; left unrebound, a mark from one request would silently disable the
 * store for every request after it.
 */
final class UncacheableMark implements UncacheableMarkInterface
{
    /** The top-level page's own scope, distinct from any real URI */
    private const TOP_LEVEL = '';

    /** @var list<string> */
    private array $stack = [];

    /** @var array<string, true> */
    private array $markedOwners = [];

    #[Override]
    public function mark(string $reason): void
    {
        $this->markedOwners[$this->currentOwner()] = true;
    }

    #[Override]
    public function isMarked(): bool
    {
        return $this->markedOwners !== [];
    }

    #[Override]
    public function isMarkedForCurrentScope(): bool
    {
        return isset($this->markedOwners[$this->currentOwner()]);
    }

    #[Override]
    public function enterHole(string $uri): void
    {
        $this->stack[] = $uri;
    }

    #[Override]
    public function leaveHole(string $uri): void
    {
        // The last (innermost) occurrence of $uri, not the first: enterHole() tolerates
        // the same URI nested twice (same path embedded twice), and only the most recent
        // push should close on a matching leaveHole().
        $index = null;
        foreach ($this->stack as $i => $owner) {
            if ($owner === $uri) {
                $index = $i;
            }
        }

        if ($index === null) {
            return;
        }

        // Truncate to just before that occurrence rather than popping exactly one entry:
        // a caller that pushed its own scope and never left it (third-party misuse, or a
        // leaked enterHole() above this one) must not permanently block this leaveHole()
        // from ever closing ITS OWN scope - a strict "only pop if it is the exact top"
        // check would leave this, and every enterHole()/leaveHole() pair below the leak,
        // open for the rest of the request. Truncating drops the leaked entry along with
        // the one actually being closed.
        $this->stack = array_slice($this->stack, 0, $index);
    }

    private function currentOwner(): string
    {
        return $this->stack === [] ? self::TOP_LEVEL : $this->stack[array_key_last($this->stack)];
    }
}
