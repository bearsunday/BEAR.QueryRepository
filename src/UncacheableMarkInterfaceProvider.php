<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Override;
use Ray\Di\ProviderInterface;

use function assert;

/**
 * Resolves UncacheableMarkInterface (app-facing) to the SAME instance
 * UncacheableMarkScopeInterface (package-internal) resolves to - a linked `->to()` binding
 * alone does not share a singleton across two different binding keys in Ray.Di; injecting the
 * already-singleton-scoped internal interface and handing it back as the app-facing one does
 * (see QueryRepositoryModule, and UncacheableMark's own docblock).
 *
 * @implements ProviderInterface<UncacheableMarkInterface>
 */
final readonly class UncacheableMarkInterfaceProvider implements ProviderInterface
{
    public function __construct(
        private UncacheableMarkScopeInterface $mark,
    ) {
    }

    #[Override]
    public function get(): UncacheableMarkInterface
    {
        assert($this->mark instanceof UncacheableMarkInterface);

        return $this->mark;
    }
}
