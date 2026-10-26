<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * Marks the current response unstorable; call it where the per-user value is produced, not
 * where it is rendered. Every resource whose own output carries that value must call it
 * itself - a mark covers only the marking resource and whatever embeds it, never an
 * unrelated sibling. Only `mark()` is app-facing: the scope mechanics live on
 * `UncacheableMarkScopeInterface`, so application code cannot forge a write frame.
 *
 * @see https://github.com/bearsunday/BEAR.QueryRepository/issues/222
 */
interface UncacheableMarkInterface
{
    /** Record that the current scope's response must not be stored in the shared cache */
    public function mark(string $reason): void;
}
