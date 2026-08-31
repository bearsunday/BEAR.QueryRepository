<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use function fclose;
use function fsockopen;
use function getenv;
use function is_array;
use function is_string;
use function parse_url;
use function sprintf;
use function str_starts_with;
use function substr_count;

/**
 * Skip a Redis-backed test when no server answers
 *
 * `@requires extension redis` checks the extension, not the server: the extension ships with the
 * dev environment while the server usually is not running. The pools are resolved eagerly when the
 * injector builds `ResourceStorage`, so without this guard an absent server fails every test of
 * the class as an *error*, and a suite that is red for the environment is a suite whose real
 * failures nobody reads. Probed per test method, so the reason is reported whichever way the
 * class is invoked.
 */
trait RequiresRedisServerTrait
{
    private const REDIS_DEFAULT_HOST = '127.0.0.1';
    private const REDIS_DEFAULT_PORT = 6379;

    /** Redis server as `{host}:{port}`, so a dev box or a CI service can point elsewhere */
    private static function redisServer(): string
    {
        $server = getenv('REDIS_SERVER');

        return is_string($server) && $server !== ''
            ? $server
            : self::REDIS_DEFAULT_HOST . ':' . self::REDIS_DEFAULT_PORT;
    }

    private static function redisDsn(): string
    {
        return 'redis://' . self::redisServer();
    }

    /**
     * Host and port of a `{host}` / `{host}:{port}` / `[{ipv6}]:{port}` endpoint
     *
     * A value that does not parse is a configuration error, and it fails rather than skipping: a
     * skip is this guard's answer to "no server", and reporting it for "unreadable setting" would
     * hide the developer's typo behind the silence the guard exists to remove. The scheme is a
     * prefix parse_url needs, not part of the value.
     *
     * @return array{0: string, 1: int}
     */
    private static function redisEndpoint(string $server): array
    {
        // An IPv6 literal has to arrive bracketed. `::1:6379` parses as host `::1`, which fsockopen
        // cannot connect to, and `::1` parses as host `:` port 1 - both probe an address nobody
        // listens on and would read as an absent server.
        if (! str_starts_with($server, '[') && substr_count($server, ':') > 1) {
            self::fail(sprintf('REDIS_SERVER needs a bracketed IPv6 address, as in [::1]:6379: %s', $server));
        }

        $endpoint = parse_url('tcp://' . $server);
        if (! is_array($endpoint) || ! isset($endpoint['host'])) {
            self::fail(sprintf('REDIS_SERVER is not a {host} or {host}:{port} endpoint: %s', $server));
        }

        return [$endpoint['host'], $endpoint['port'] ?? self::REDIS_DEFAULT_PORT];
    }

    private static function skipWithoutRedisServer(): void
    {
        [$host, $port] = self::redisEndpoint(self::redisServer());
        // Reachability, not a handshake: the adapter is what speaks the protocol, and a test that
        // cannot connect at all is the only case this decides.
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);
        if ($connection === false) {
            // Not conditioned on a CI marker: `CI` is set by enough local tooling that keying on it
            // fails the run on a dev box, which is the noise this guard removes. The workflow pings
            // the server before the suite, so a CI job without one is already red there.
            self::markTestSkipped(sprintf('no Redis server on %s', self::redisServer()));
        }

        fclose($connection);
    }
}
