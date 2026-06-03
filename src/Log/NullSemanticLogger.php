<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

/**
 * No-op semantic logger
 *
 * Zero-cost default when cache logging is turned off. open() returns an empty
 * id (which SafeSemanticLogger and disciplined call sites treat as "no close
 * needed"), and flush() returns an empty log session.
 *
 * Every parameter is dictated by SemanticLoggerInterface and intentionally
 * unused in this no-op implementation. The methods are trivial no-ops with no
 * behavior to assert, so each is excluded from coverage rather than carrying
 * tests that prove nothing.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
final class NullSemanticLogger implements SemanticLoggerInterface
{
    private const EMPTY_SCHEMA_URL = 'https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json';

    /** @codeCoverageIgnore */
    #[Override]
    public function open(AbstractContext $context): string
    {
        return '';
    }

    /** @codeCoverageIgnore */
    #[Override]
    public function event(AbstractContext $context): void
    {
    }

    /** @codeCoverageIgnore */
    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
    }

    /**
     * {@inheritDoc}
     *
     * @codeCoverageIgnore
     */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        return new LogJson(self::EMPTY_SCHEMA_URL, [], [], [], $links);
    }
}
