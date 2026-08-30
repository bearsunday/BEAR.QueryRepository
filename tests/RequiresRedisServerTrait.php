<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use function explode;
use function fclose;
use function fsockopen;
use function getenv;
use function is_string;
use function sprintf;

/**
 * Skip a Redis-backed test class when no server answers
 *
 * `@requires extension redis` checks the extension, not the server: the extension ships with the
 * dev environment while the server usually is not running. The pools are resolved eagerly when the
 * injector builds `ResourceStorage`, so an absent server fails every test of the class as an
 * *error* - a dozen red entries a reader has to diff against another branch to dismiss, which is
 * how a real failure hides. A skip reports the same fact without the false alarm.
 */
trait RequiresRedisServerTrait
{
    private const REDIS_DEFAULT_SERVER = '127.0.0.1:6379';

    /** Redis server as `{host}:{port}`, so a dev box or a CI service can point elsewhere */
    private static function redisServer(): string
    {
        $server = getenv('REDIS_SERVER');

        return is_string($server) && $server !== '' ? $server : self::REDIS_DEFAULT_SERVER;
    }

    private static function redisDsn(): string
    {
        return 'redis://' . self::redisServer();
    }

    private static function skipWithoutRedisServer(): void
    {
        $parts = explode(':', self::redisServer());
        // Reachability, not a handshake: the adapter is what speaks the protocol, and a test that
        // cannot connect at all is the only case this decides.
        $connection = @fsockopen($parts[0], (int) ($parts[1] ?? 6379), $errno, $errstr, 1);
        if ($connection === false) {
            self::markTestSkipped(sprintf('no Redis server on %s', self::redisServer()));
        }

        fclose($connection);
    }
}
