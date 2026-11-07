<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use RuntimeException;

final class FakeUnflushableLogger implements SemanticLoggerInterface
{
    #[Override]
    public function open(AbstractContext $context): string
    {
        return '1';
    }

    #[Override]
    public function event(AbstractContext $context): void
    {
    }

    #[Override]
    public function close(AbstractContext $context, string $openId): void
    {
    }

    /** {@inheritDoc} */
    #[Override]
    public function flush(array $links = []): LogJson
    {
        throw new RuntimeException('the session cannot be flushed');
    }
}
