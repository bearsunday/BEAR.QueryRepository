<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

use Override;

/** One session for the whole process: the request in progress under PHP-FPM and the CLI */
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
     * A compiled app serializes the injector between requests; no session crosses that boundary
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
