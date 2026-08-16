<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\LogJson;
use Override;

/** Writes a session only when the policy keeps it */
final class PolicyLogWriter implements LogWriterInterface
{
    public function __construct(
        private RetentionPolicyInterface $policy,
        private LogWriterInterface $writer,
    ) {
    }

    #[Override]
    public function write(LogJson $log): void
    {
        if (! $this->policy->keeps($log)) {
            return;
        }

        $this->writer->write($log);
    }
}
