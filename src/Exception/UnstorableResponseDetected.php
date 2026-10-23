<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Exception;

use function sprintf;

/**
 * A response marked unstorable (issue #222) was about to be served - thrown only when
 * DevUnstorableGateModule is installed
 *
 * The package default is to skip the write silently and record a `put_skipped` event: the same
 * `#[Cacheable]` page may legitimately render a form only for some users, so throwing by
 * default would fail a response that is otherwise correct. Installing DevUnstorableGateModule
 * turns that skip into this exception instead, so a developer sees it immediately rather than
 * discovering it in the semantic log.
 */
final class UnstorableResponseDetected extends RuntimeException
{
    public function __construct(string $uri, string $reason)
    {
        parent::__construct(sprintf('%s is unstorable for this request (%s)', $uri, $reason));
    }
}
