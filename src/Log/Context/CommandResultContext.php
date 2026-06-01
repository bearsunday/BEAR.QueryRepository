<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Close of a command scope: the resulting HTTP status code.
 */
final class CommandResultContext extends AbstractContext
{
    public const TYPE = 'command_result';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/command_result.json';

    public function __construct(
        public readonly int $code,
    ) {
    }
}
