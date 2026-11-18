<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

/**
 * The key every entry and every Surrogate-Key of the application carries: {@see AppSurrogateKeyModule}
 *
 * @internal
 */
final readonly class AppSurrogateKey
{
    public function __construct(
        public string $key,
    ) {
    }
}
