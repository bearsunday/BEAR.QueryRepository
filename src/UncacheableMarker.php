<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;

/**
 * The app-facing `UncacheableMarkInterface` implementation: a thin wrapper around the
 * package-internal `UncacheableMarkScopeInterface`, delegating `mark()` to it and declaring
 * no other method - genuinely absent from the object, not merely undeclared on its type.
 * Bound without `Scope::SINGLETON`: a fresh instance is constructed on every resolution,
 * always wrapping whichever `UncacheableMarkScopeInterface` instance is currently bound, so
 * it holds no per-request state of its own.
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
