<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log;

/** Whether the host serves more than one request in a process */
interface ConcurrentRuntimeInterface
{
    public function isConcurrent(): bool;
}
