<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use RuntimeException;

/**
 * A semantic logger that throws on every call, to prove that logging failures
 * never escape into the cache read/write path when wrapped by SafeSemanticLogger.
 */
final class ThrowingSemanticLogger implements SemanticLoggerInterface
{
    #[Override]
    public function open(AbstractContext $context): string
    {
        throw new RuntimeException('logger down');
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
        throw new RuntimeException('logger down');
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
        throw new RuntimeException('logger down');
    }

    /** {@inheritDoc} */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        throw new RuntimeException('logger down');
    }
}
