<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use Koriym\SemanticLogger\AbstractContext;
use Koriym\SemanticLogger\LogJson;
use Koriym\SemanticLogger\SemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function serialize;
use function unserialize;

class SafeSemanticLoggerTest extends TestCase
{
    public function testRecoversToFreshSessionAfterFlushFailure(): void
    {
        // A delegate whose flush() always throws (mimics a dirty/unclosed session).
        $flaky = new class implements SemanticLoggerInterface {
            public function open(AbstractContext $context): string
            {
                return 'x';
            }

            public function event(AbstractContext $context): void
            {
            }

            public function close(AbstractContext $context, string $openId): void
            {
            }

            public function flush(array $links = []): LogJson
            {
                throw new RuntimeException('flush failed');
            }
        };
        $safe = new SafeSemanticLogger($flaky);

        $id = $safe->open(new GetContext('page://self/x'));
        $safe->close(new CacheMissContext('resource'), $id);
        // The delegate throws on flush; the failure is swallowed and an empty log returned.
        $this->assertSame([], $safe->flush()->toArray()['open']);

        // Recovery: the next session uses a fresh delegate and logs normally.
        $id2 = $safe->open(new GetContext('page://self/y'));
        $safe->close(new CacheHitContext('resource'), $id2);
        $this->assertCount(1, $safe->flush()->toArray()['open']);
    }

    public function testSerializesWithoutCarryingSessionState(): void
    {
        $safe = new SafeSemanticLogger(new SemanticLogger());
        $safe->open(new GetContext('page://self/x')); // leave a session open (dirty)

        $restored = unserialize(serialize($safe));
        $this->assertInstanceOf(SafeSemanticLogger::class, $restored);

        // The restored logger is a fresh session, not the dirty one.
        $id = $restored->open(new GetContext('page://self/z'));
        $restored->close(new CacheMissContext('resource'), $id);
        $this->assertCount(1, $restored->flush()->toArray()['open']);
    }

    public function testEventFailureIsSwallowed(): void
    {
        // Delegate succeeds on open() (so SafeSemanticLogger stays unbroken and enters
        // event()'s try) but throws on event(): the failure must be swallowed.
        $flaky = new class implements SemanticLoggerInterface {
            public function open(AbstractContext $context): string
            {
                return 'x';
            }

            public function event(AbstractContext $context): void
            {
                throw new RuntimeException('event failed');
            }

            public function close(AbstractContext $context, string $openId): void
            {
            }

            public function flush(array $links = []): LogJson
            {
                return new LogJson('https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json', [], [], [], $links);
            }
        };
        $safe = new SafeSemanticLogger($flaky);

        $safe->open(new GetContext('page://self/x'));
        $safe->event(new CacheMissContext('resource')); // throws inside; must not escape
        // The session is marked broken and flush() returns an empty log without throwing.
        $this->assertSame([], $safe->flush()->toArray()['open']);
    }

    public function testCloseFailureIsSwallowed(): void
    {
        // Delegate succeeds on open() but throws on close(): the failure must be swallowed.
        $flaky = new class implements SemanticLoggerInterface {
            public function open(AbstractContext $context): string
            {
                return 'x';
            }

            public function event(AbstractContext $context): void
            {
            }

            public function close(AbstractContext $context, string $openId): void
            {
                throw new RuntimeException('close failed');
            }

            public function flush(array $links = []): LogJson
            {
                return new LogJson('https://koriym.github.io/Koriym.SemanticLogger/schemas/semantic-log.json', [], [], [], $links);
            }
        };
        $safe = new SafeSemanticLogger($flaky);

        $id = $safe->open(new GetContext('page://self/x'));
        $safe->close(new CacheMissContext('resource'), $id); // throws inside; must not escape
        $this->assertSame([], $safe->flush()->toArray()['open']);
    }
}
