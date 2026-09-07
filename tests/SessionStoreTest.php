<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Fake\FakeKeyedSessionStore;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\GetContext;
use BEAR\QueryRepository\Log\LogSinkInterface;
use BEAR\QueryRepository\Log\ProcessSession;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

/** #179: two requests sharing one facade must neither cross-nest nor drop each other's log */
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

    /** A keyed store is a live request context; once the sink refuses the host, no call may reach it */
    public function testARefusedSinkKeepsEveryCallOffTheStore(): void
    {
        $store = new FakeKeyedSessionStore();
        $refusing = new class implements LogSinkInterface {
            public function arm(SemanticLoggerInterface $logger): bool
            {
                return false;
            }

            public function flush(SemanticLoggerInterface $logger): void
            {
            }
        };
        $logger = new SafeSemanticLogger($refusing, $store);

        $id = $logger->open(new GetContext('app://self/a'));
        $this->assertFalse($logger->isTopLevel());
        $logger->event(new CacheHitContext('view'));
        $logger->close(new CacheHitContext('view'), $id);
        $this->assertTrue($logger->isTopLevel());
        $this->assertSame([], $logger->flush()->open, 'a refused sink records nothing');
        $this->assertSame(0, $store->calls, 'the store was never consulted, flush included');
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
