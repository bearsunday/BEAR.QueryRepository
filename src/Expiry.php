<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class Expiry
{
    /** @var array<string, int> */
    private array $time;

    public function __construct(int $short = 60, int $medium = 3600, int $long = 86400, int $never = 31_536_000)
    {
        $this->time = [
            'short' => $short,
            'medium' => $medium,
            'long' => $long,
            'never' => $never,
        ];
    }

    public function getTime(string $type): int
    {
        return $this->time[$type];
    }
}
