<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;

/**
 * Decides whether a flushed session is worth writing
 *
 * The decision is taken on the complete session, and keeps or drops it whole: a session's value
 * lies in the correlation between its entries - which tags were stored against which tags were
 * purged - so a filter that kept single events would destroy what makes it readable.
 *
 * An app that wants "record this one request in full" (a debug header, an internal IP)
 * implements this interface and delegates to the shipped policy for everything else: the
 * condition is the app's to define, since only the app knows what authorizes it.
 */
interface RetentionPolicyInterface
{
    public function keeps(LogJson $log): bool;
}
