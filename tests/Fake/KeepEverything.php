<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Fake;

use BEAR\QueryRepository\Log\RetentionPolicyInterface;
use Koriym\SemanticLogger\LogJson;
use Override;

/** An app policy that keeps a session the production default would drop */
final class KeepEverything implements RetentionPolicyInterface
{
    #[Override]
    public function keeps(LogJson $log): bool
    {
        return true;
    }
}
