<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Override;
use Ray\Di\Di\Set;
use Ray\Di\MultiBinding\Map;
use Ray\Di\ProviderInterface;

/**
 * ShutdownFlush with every writer added to the LogWriterInterface set
 *
 * The set is injected here, not into ShutdownFlush, so an app that binds ShutdownFlush without
 * declaring the set builds it as before.
 *
 * @implements ProviderInterface<ShutdownFlush>
 * @internal
 */
final class ShutdownFlushProvider implements ProviderInterface
{
    /** @param Map<LogWriterInterface> $receivers */
    public function __construct(
        private LogWriterInterface $writer,
        #[Set(LogWriterInterface::class)]
        private Map $receivers,
        private ConcurrentRuntimeInterface $runtime = new HostRuntime(),
    ) {
    }

    #[Override]
    public function get(): ShutdownFlush
    {
        return new ShutdownFlush($this->writer, $this->runtime, $this->receivers->getIterator());
    }
}
