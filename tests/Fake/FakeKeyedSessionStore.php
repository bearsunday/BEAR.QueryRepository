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

    /** @return array{key: string} */
    public function __serialize(): array
    {
        return ['key' => $this->key];
    }

    /** @param array{key: string} $data */
    public function __unserialize(array $data): void
    {
        $this->key = $data['key'];
        $this->sessions = [];
    }
}
