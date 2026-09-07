<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Fake;

use BEAR\QueryRepository\Log\Session;
use BEAR\QueryRepository\Log\SessionStoreInterface;
use Override;

/** A concurrent host's keyed store, with the request key set by the test instead of a coroutine id */
final class FakeKeyedSessionStore implements SessionStoreInterface
{
    public string $key = 'a';

    /** @var array<string, Session> */
    public array $sessions = [];

    /** Every current()/forget(), so a test can assert the store was never consulted */
    public int $calls = 0;

    #[Override]
    public function current(): Session
    {
        $this->calls++;

        return $this->sessions[$this->key] ??= new Session();
    }

    #[Override]
    public function forget(): void
    {
        $this->calls++;
        unset($this->sessions[$this->key]);
    }
}
