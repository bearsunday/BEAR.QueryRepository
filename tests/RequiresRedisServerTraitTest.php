<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\TestCase;

/**
 * The endpoint the guard probes
 *
 * A misparsed endpoint skips a class against a server that answers, which is the false alarm the
 * guard exists to remove - so the parse has its own test even though the trait is scaffolding.
 * The class uses the trait to reach its private helper and never calls the skip itself.
 */
final class RequiresRedisServerTraitTest extends TestCase
{
    use RequiresRedisServerTrait;

    public function testHostAndPort(): void
    {
        $this->assertSame(['127.0.0.1', 6379], self::redisEndpoint('127.0.0.1:6379'));
        $this->assertSame(['redis.internal', 6380], self::redisEndpoint('redis.internal:6380'));
    }

    public function testABracketedIpv6EndpointKeepsItsBrackets(): void
    {
        // Splitting on `:` read this as host `[` with no port; fsockopen needs the brackets.
        $this->assertSame(['[::1]', 6379], self::redisEndpoint('[::1]:6379'));
    }

    public function testAHostWithNoPortGetsTheRedisDefault(): void
    {
        $this->assertSame(['redis.internal', 6379], self::redisEndpoint('redis.internal'));
    }
}
