<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\LogWriterInterface;
use Koriym\SemanticLogger\LogJson;
use Override;

final class FakePrimaryWriter implements LogWriterInterface
{
    #[Override]
    public function write(LogJson $log): void
    {
        FakeSessionRecord::$received[] = [self::class, $log];
    }
}
