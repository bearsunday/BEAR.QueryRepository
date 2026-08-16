<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use JsonSerializable;
use Koriym\SemanticLogger\AbstractContext;
use Override;

/**
 * Close of a manual_purge scope: whether the local pools were invalidated.
 */
final class ManualPurgeResultContext extends AbstractContext implements JsonSerializable
{
    public const TYPE = 'manual_purge_result';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/manual_purge_result.json';

    public function __construct(
        public readonly bool $purged,
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function jsonSerialize(): array
    {
        return ['result' => $this->purged ? 'purged' : 'failed'];
    }
}
