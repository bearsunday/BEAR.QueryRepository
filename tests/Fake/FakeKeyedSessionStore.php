<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Fake;

use BEAR\QueryRepository\Log\Session;
use BEAR\QueryRepository\Log\SessionStoreInterface;
use Override;

/**
 * Stands in for a concurrent host's keyed store, without a real coroutine runtime
 *
 * A Swoole/RoadRunner binding would key sessions by coroutine id or worker request id; this
 * fake keys them by a public string a test sets directly, which is enough to prove
 * SafeSemanticLogger never lets two keys share or drop a session.
 */
final class FakeKeyedSessionStore implements SessionStoreInterface
{
    public string $key = 'a';

    /** @var array<string, Session> */
    public array $sessions = [];

    #[Override]
    public function current(): Session
    {
        return $this->sessions[$this->key] ??= new Session();
    }

    #[Override]
    public function forget(): void
    {
        unset($this->sessions[$this->key]);
    }
}
