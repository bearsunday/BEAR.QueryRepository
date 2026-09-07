<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Override;

/**
 * The FPM/CLI default: one lazily created session for the whole process
 *
 * Correct because the premise it stands on is true there and only there - one process
 * serves one request, so "the whole process" and "the request in progress" name the same
 * span. A concurrent host does not get to opt out by leaving this bound; it must replace it.
 */
final class ProcessSession implements SessionStoreInterface
{
    private Session|null $session = null;

    #[Override]
    public function current(): Session
    {
        return $this->session ??= new Session();
    }

    #[Override]
    public function forget(): void
    {
        $this->session = null;
    }

    /**
     * Carry nothing: a compiled app serializes the injector between requests, and a session
     * that survived that boundary would be the very cross-request sharing this class exists
     * to avoid for the host it is right for.
     *
     * @return array{}
     */
    public function __serialize(): array
    {
        return [];
    }

    /** @param array{} $data */
    public function __unserialize(array $data): void
    {
        $this->session = null;
    }
}
