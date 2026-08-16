<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use Koriym\SemanticLogger\SemanticLogger;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function serialize;
use function unserialize;

use const JSON_UNESCAPED_SLASHES;

/**
 * SafeSemanticLogger: depth tracking and the serialization boundary
 *
 * Since koriym/semantic-logger 0.9 the core logger is a total function, so the
 * throwing-delegate fakes that used to pin the old swallow-and-tombstone guard
 * are gone: a delegate that honors the interface contract never throws, and the
 * misuse itself is recorded in-band as `semantic_logger_error` diagnostics
 * (pinned here against a real SemanticLogger).
 */
class SafeSemanticLoggerTest extends TestCase
{
    use SemanticLogTreeTrait;

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

    public function testLifoViolationIsRecordedAsDiagnosticAndFlushRecovers(): void
    {
        // Pin the 0.9 contract against a REAL SemanticLogger delegate (no fake):
        // closing scope A while B is still open violates LIFO order. The core
        // never throws — the violation is recorded in-band as a diagnostic.
        $safe = new SafeSemanticLogger(new SemanticLogger());

        $idA = $safe->open(new GetContext('page://self/a'));
        $safe->open(new GetContext('page://self/b'));
        $safe->close(new CacheMissContext('resource'), $idA); // violation; must not escape

        $log = $safe->flush()->toArray();
        // The tree topology is preserved: scope b stays nested inside scope a.
        $this->assertCount(1, $log['open']);
        $this->assertSame(2, self::maxOpenDepth($log));
        // The violation is marked at the exact failure point, with the culprit id.
        $diagnostic = self::eventContextJsonOf($log, 'semantic_logger_error');
        $this->assertNotNull($diagnostic, 'the LIFO violation is recorded as a diagnostic');
        $this->assertStringContainsString('"kind":"close_id_mismatch"', $diagnostic);
        $this->assertStringContainsString('"relatedId":"' . $idA . '"', $diagnostic);
        // Nothing is lost silently: the unclosed scopes are enumerated at flush.
        $eventsJson = (string) json_encode($log['events'] ?? [], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('"kind":"unclosed_at_flush"', $eventsJson);
        $this->assertStringContainsString('"unclosedIds":["get_1","get_2"]', $eventsJson);

        // flush() always resets the session, so the next one logs normally.
        $id = $safe->open(new GetContext('page://self/c'));
        $safe->close(new CacheHitContext('resource'), $id);
        $this->assertCount(1, $safe->flush()->toArray()['open']);
    }
}
