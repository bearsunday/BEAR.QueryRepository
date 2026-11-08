<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\LogWriterInterface;
use Koriym\SemanticLogger\LogJson;
use Override;
use RuntimeException;

final class FakeThrowingReceiver implements LogWriterInterface
{
    #[Override]
    public function write(LogJson $log): void
    {
        throw new RuntimeException('the event store is down');
    }
}
