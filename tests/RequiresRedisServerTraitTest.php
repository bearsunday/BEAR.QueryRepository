<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The endpoint the guard probes
 *
 * A misparsed endpoint probes an address nobody listens on, which the guard reports as an absent
 * server: the class skips, the suite stays green, and it stops covering Redis without saying so.
 * That is why the parse has a test of its own while the rest of the trait does not. The class uses
 * the trait to reach the parse and never probes.
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
        // fsockopen takes the literal only in brackets.
        $this->assertSame(['[::1]', 6379], self::redisEndpoint('[::1]:6379'));
    }

    public function testAHostWithNoPortGetsTheRedisDefault(): void
    {
        $this->assertSame(['redis.internal', 6379], self::redisEndpoint('redis.internal'));
    }

    /** @return list<array{0: string}> */
    public static function unreadableSettings(): array
    {
        return [
            ['::1:6379'],    // parses as host `::1`, which fsockopen refuses
            ['::1'],         // parses as host `:` port 1
            ['garbage:xyz'], // no port to read
            ['redis://x:1'], // a DSN, not an endpoint: parses as host `redis`
        ];
    }

    #[DataProvider('unreadableSettings')]
    public function testAnUnreadableSettingFailsInsteadOfSkipping(string $server): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($server);

        self::redisEndpoint($server);
    }
}
