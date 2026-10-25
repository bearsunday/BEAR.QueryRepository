<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

/**
 * The app-facing `UncacheableMarkInterface` implementation (see that interface's own
 * docblock): a thin wrapper around the package-internal `UncacheableMarkScopeInterface`,
 * delegating `mark()` to it and declaring no other method. An application's own resource,
 * injected this class under `UncacheableMarkInterface`, has no `enterWrite()`/
 * `isCurrentWriteFor()`/etc to call - not merely undeclared on the type it was handed, but
 * genuinely absent from the object itself - a fatal `Error`, not a leak.
 *
 * Bound WITHOUT `Scope::SINGLETON` (see `QueryRepositoryModule`): a fresh instance is
 * constructed on every `UncacheableMarkInterface` resolution, always wrapping whichever
 * `UncacheableMarkScopeInterface` instance is currently bound - so giving each request its
 * own fresh `UncacheableMarkScopeInterface` instance (the SAME `UncacheableMark` instance's
 * own docblock explains why only that key, never this class) is enough; this class itself
 * holds no per-request state of its own.
 */
final readonly class UncacheableMarker implements UncacheableMarkInterface
{
    public function __construct(
        private UncacheableMarkScopeInterface $scope,
    ) {
    }

    #[Override]
    public function mark(string $reason): void
    {
        $this->scope->mark($reason);
    }
}
