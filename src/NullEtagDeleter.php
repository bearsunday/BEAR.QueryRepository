<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class NullEtagDeleter implements EtagDeleterInterface
{
    public function __invoke(string $etag): void
    {
    }
}
