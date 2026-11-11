<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

use function array_shift;
use function bin2hex;
use function explode;
use function fclose;
use function fwrite;
use function is_dir;
use function json_decode;
use function microtime;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sprintf;
use function stream_get_contents;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * The 304 a web server sends, read off the socket
 *
 * HttpCache::transfer() sets a response, which only a web SAPI has: `php -S` runs
 * tests/Fake/not-modified-server.php as an application's bootstrap would run.
 */
class NotModifiedResponseTest extends TestCase
{
    private static Process $server;
    private static string $address = '';
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/qr-not-modified-' . bin2hex(random_bytes(6));
        mkdir(self::$dir);
        $free = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($free);
        self::$address = (string) stream_socket_get_name($free, false);
        fclose($free);

        // Errors go to the server's log, so that only what the application sends is in the response.
        self::$server = new Process(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', self::$address, __DIR__ . '/Fake/not-modified-server.php'],
            null,
            ['QR_NOT_MODIFIED_DIR' => self::$dir],
        );
        self::$server->start();
        $deadline = microtime(true) + 10;
        while (self::$server->isRunning() && microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://' . self::$address, $errno, $error, 1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        self::markTestSkipped(sprintf('php -S did not start on %s: %s', self::$address, self::$server->getErrorOutput()));
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop(1);
        if (! is_dir(self::$dir)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir(self::$dir);
    }

    public function testThe304RepeatsTheCachingHeadersOfThe200AndHasNoContentType(): void
    {
        $ok = $this->get();
        $this->assertSame(200, $ok['status'], self::$server->getErrorOutput());
        $this->assertSame('s-maxage=600, max-age=0', $ok['headers']['cache-control'] ?? null);
        $this->assertSame('application/json', $ok['headers']['content-type'] ?? null);
        $etag = $ok['headers']['etag'] ?? '';
        $this->assertNotSame('', $etag);

        $notModified = $this->get($etag);

        $this->assertSame(304, $notModified['status'], self::$server->getErrorOutput());
        $this->assertSame($etag, $notModified['headers']['etag'] ?? null);
        $this->assertSame('s-maxage=600, max-age=0', $notModified['headers']['cache-control'] ?? null);
        $this->assertArrayNotHasKey('content-type', $notModified['headers'], 'a cache would copy it onto the response it stored');
        $this->assertArrayNotHasKey('last-modified', $notModified['headers']);
        $this->assertSame('', $notModified['body']);
    }

    public function testAValidatorThatDoesNotMatchIsAnsweredInFull(): void
    {
        $ok = $this->get('"stale"');

        $this->assertSame(200, $ok['status'], self::$server->getErrorOutput());
        $this->assertSame('application/json', $ok['headers']['content-type'] ?? null);
        $this->assertSame(['id' => 1], json_decode($ok['body'], true));
    }

    /** @return array{status: int, headers: array<string, string>, body: string} header names in lower case */
    private function get(string|null $ifNoneMatch = null): array
    {
        $socket = stream_socket_client('tcp://' . self::$address, $errno, $error, 5);
        $this->assertIsResource($socket, (string) $error);
        stream_set_timeout($socket, 10);
        fwrite($socket, "GET / HTTP/1.0\r\nHost: " . self::$address . "\r\n" . ($ifNoneMatch === null ? '' : "If-None-Match: {$ifNoneMatch}\r\n") . "\r\n");
        $response = (string) stream_get_contents($socket);
        fclose($socket);

        [$head, $body] = explode("\r\n\r\n", $response, 2) + [1 => ''];
        $lines = explode("\r\n", $head);
        $status = (int) substr((string) array_shift($lines), 9, 3);
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower($name)] = trim($value);
        }

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
