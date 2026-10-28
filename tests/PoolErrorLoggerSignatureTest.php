<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\PoolErrorLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The tests run on psr/log 3, so a typed `log()` loads here and fails only in an app on psr/log 1.x,
 * whose `LoggerInterface::log()` declares no parameter types. Pin the untyped signature instead.
 */
class PoolErrorLoggerSignatureTest extends TestCase
{
    public function testLogParametersAreUntypedForPsrLog1(): void
    {
        $params = (new ReflectionMethod(PoolErrorLogger::class, 'log'))->getParameters();

        $this->assertNull($params[0]->getType(), '$level');
        $this->assertNull($params[1]->getType(), '$message');
    }
}
