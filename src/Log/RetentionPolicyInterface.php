<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;

/**
 * Decides whether a flushed session is worth writing
 *
 * A healthy read carries no information: every entry says the cache did what it was told.
 * The decision is therefore taken after the session is complete - the whole tree is kept or
 * the whole tree is dropped, because a session's value lies in the correlation between its
 * entries (which tags were stored against which tags were purged), not in single events.
 *
 * An app that wants "record this one request in full" (a debug header, an internal IP)
 * implements this interface and delegates to the shipped policy for everything else: the
 * condition is the app's to define, since only the app knows what authorizes it.
 */
interface RetentionPolicyInterface
{
    public function keeps(LogJson $log): bool;
}
