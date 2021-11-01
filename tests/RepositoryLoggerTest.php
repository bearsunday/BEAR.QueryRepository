<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

class RepositoryLoggerTest extends TestCase
{
    public function testLog(): void
    {
        $logger = new RepositoryLogger();
        $logger->log('get %s', 1);
        $logger->log('put %s %s', 2, 3);
        $logString = (string) $logger;
        $this->assertSame('get 1
put 2 3', $logString);
    }
}
