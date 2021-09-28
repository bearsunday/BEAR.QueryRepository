<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

interface EtagDeleterInterface
{
    public function __invoke(string $etag): void;
}
