<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

use function array_key_last;
use function array_pop;
use function array_slice;

/**
 * {@inheritDoc}
 *
 * Bound Scope::SINGLETON (see QueryRepositoryModule): one instance per DI container, which is
 * one per request in the usual BEAR.Sunday bootstrap - the same lifetime ResourceStorage and
 * QueryRepository already rely on. A long-running worker (Swoole, RoadRunner) that reuses one
 * container across requests must rebind this per request, the same way it already must for
 * ServerContextInterface; left unrebound, a mark from one request would silently disable the
 * store for every request after it - and (#234) would leave a stale tick/write-scope/
 * interceptor-depth behind too, the same reset `DevUnstorableGateModule`'s
 * `ThrowingUnstorableWriteObserver` (bound SINGLETON for the same reason) also needs.
 */
final class UncacheableMark implements UncacheableMarkInterface
{
    /** The top-level page's own scope, distinct from any real URI */
    private const TOP_LEVEL = '';

    /** @var list<string> */
    private array $stack = [];

    /** @var array<string, true> */
    private array $markedOwners = [];

    /** Advanced by every mark(), any owner - what wasMarkedSinceCurrentWrite() compares against */
    private int $tick = 0;

    /** @var list<ResourceObject> the ResourceObject instance owning each currently open enterWrite(), parallel to $writeSnapshots */
    private array $writeOwners = [];

    /** @var list<int> the tick snapshotted at each currently open enterWrite() */
    private array $writeSnapshots = [];
    private int $interceptorDepth = 0;

    #[Override]
    public function mark(string $reason): void
    {
        $this->markedOwners[$this->currentOwner()] = true;
        $this->tick++;
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

    #[Override]
    public function isNested(): bool
    {
        return $this->stack !== [];
    }

    #[Override]
    public function enterWrite(ResourceObject $ro): void
    {
        $this->writeOwners[] = $ro;
        $this->writeSnapshots[] = $this->tick;
    }

    #[Override]
    public function setWriteOwner(ResourceObject $ro): void
    {
        if ($this->writeOwners === []) {
            return;
        }

        array_pop($this->writeOwners);
        $this->writeOwners[] = $ro;
    }

    #[Override]
    public function leaveWrite(): void
    {
        // A plain pop, unlike leaveHole()'s URI-matching truncation: write scopes are not
        // exposed to third-party callers by URI identity, only CacheInterceptor opens them,
        // strictly call-stack nested around its own proceed()+put().
        array_pop($this->writeOwners);
        array_pop($this->writeSnapshots);
    }

    #[Override]
    public function wasMarkedSinceCurrentWrite(): bool
    {
        if ($this->writeSnapshots === []) {
            // No write is open (e.g. a direct, non-AOP put() call outside any interceptor):
            // nothing to compare against, so this half of the #234 predicate stays silent -
            // isCurrentWriteFor() is what keeps a caller from trusting this answer at all here.
            return false;
        }

        return $this->tick !== $this->writeSnapshots[array_key_last($this->writeSnapshots)];
    }

    #[Override]
    public function isCurrentWriteFor(ResourceObject $ro): bool
    {
        if ($this->writeOwners === []) {
            return false;
        }

        return $this->writeOwners[array_key_last($this->writeOwners)] === $ro;
    }

    #[Override]
    public function enterInterceptor(): void
    {
        $this->interceptorDepth++;
    }

    #[Override]
    public function leaveInterceptor(): bool
    {
        $this->interceptorDepth--;

        return $this->interceptorDepth === 0;
    }

    private function currentOwner(): string
    {
        return $this->stack === [] ? self::TOP_LEVEL : $this->stack[array_key_last($this->stack)];
    }
}
