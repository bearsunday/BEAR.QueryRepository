<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

/**
 * Capability: report whether the next operation would be top-level
 *
 * Implemented by loggers that track their open-scope depth. Cache call sites use
 * this capability to decide whether a direct (non-AOP) operation is rooted in its
 * own manual scope (manual_store / manual_purge / manual_invalidate), which marks
 * the operation as application-initiated and gives its outcome a close to ride on.
 * Depending on this interface instead of a concrete logger class lets any custom
 * SemanticLoggerInterface decorator opt in to manual-scope rooting.
 */
interface TopLevelAwareInterface
{
    /**
     * Whether no operation scope is currently open (the next open would be top-level)
     */
    public function isTopLevel(): bool;
}
