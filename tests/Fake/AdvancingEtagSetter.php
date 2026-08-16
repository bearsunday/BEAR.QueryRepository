<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use Override;

/** Deterministic test clock for Last-Modified generation. */
final class AdvancingEtagSetter implements EtagSetterInterface
{
    private int $nextTime = 1000;
    private readonly EtagSetter $etagSetter;

    public function __construct()
    {
        $this->etagSetter = new EtagSetter();
    }

    #[Override]
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null): void
    {
        ($this->etagSetter)($ro, $time ?? $this->nextTime++, $httpCache);
    }
}
