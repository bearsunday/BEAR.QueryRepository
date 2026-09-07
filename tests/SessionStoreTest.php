<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Fake\FakeKeyedSessionStore;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\ProcessSession;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

/**
 * #179: two concurrent requests sharing one facade must not cross-nest or drop each other's log
 *
 * FakeKeyedSessionStore stands in for a Swoole/RoadRunner store keyed by coroutine or worker
 * request id - real concurrency is not exercised here, only the contract SafeSemanticLogger
 * relies on: switching the store's key mid-flight resolves a different session, and each
 * session's own open/close/flush cycle is unaffected by what happens under another key.
 */
class SessionStoreTest extends TestCase
{
    use SemanticLogTreeTrait;

    public function testTwoKeysNeitherCrossNestNorDropEachOthersSession(): void
    {
        $store = new FakeKeyedSessionStore();
        $logger = new SafeSemanticLogger(null, $store);

        $store->key = 'a';
        $idA = $logger->open(new GetContext('app://self/a'));

        $store->key = 'b';
        $idB = $logger->open(new GetContext('app://self/b'));
        $this->assertFalse($logger->isTopLevel(), 'the get scope just opened under key b is still open');
        $logger->event(new CacheHitContext('view'));
        $logger->close(new CacheHitContext('view'), $idB);
        $this->assertTrue($logger->isTopLevel(), 'key b closed its only scope');

        $logB = $logger->flush()->toArray();
        $this->assertCount(1, $logB['open'], 'only the session recorded under key b is flushed');
        $this->assertStringContainsString('app://self/b', (string) self::contextJsonOf($logB, 'get'));
        $this->assertNull(self::eventContextJsonOf($logB, 'semantic_logger_error'), 'key b never touched key a, so nothing looks unclosed');

        $store->key = 'a';
        $this->assertFalse($logger->isTopLevel(), "key a's scope, opened before the switch to b, is still open");
        $logger->close(new CacheHitContext('view'), $idA);
        $logA = $logger->flush()->toArray();
        $this->assertCount(1, $logA['open'], 'only the session recorded under key a is flushed');
        $this->assertStringContainsString('app://self/a', (string) self::contextJsonOf($logA, 'get'));
        $this->assertNull(self::eventContextJsonOf($logA, 'semantic_logger_error'), 'key a was interleaved, not violated');

        $this->assertSame([], $store->sessions, 'both flushes forgot their key; nothing is left to leak into a third request');
    }

    public function testProcessSessionStartsFreshAfterSerialization(): void
    {
        $store = new ProcessSession();
        $store->current()->depth = 3; // a mid-request scope, left open

        $restored = unserialize(serialize($store));
        $this->assertInstanceOf(ProcessSession::class, $restored);
        $this->assertSame(0, $restored->current()->depth, 'no session crosses the serialization boundary, so the next one starts at depth 0');
    }
}
