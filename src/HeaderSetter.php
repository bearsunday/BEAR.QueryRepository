<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

use function sprintf;
use function str_contains;

final readonly class HeaderSetter
{
    public function __construct(
        private EtagSetterInterface $etagSetter,
    ) {
    }

    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null): void
    {
        ($this->etagSetter)($ro, $time, $httpCache);

        if ($cacheControlMaxAge !== null && $cacheControlMaxAge > 0) {
            $this->setCacheControlMaxAge($ro, $cacheControlMaxAge);
        }
    }

    private function setCacheControlMaxAge(ResourceObject $ro, int $age): void
    {
        $setMaxAge = sprintf('max-age=%d', $age);
        if (! isset($ro->headers[Header::CACHE_CONTROL])) {
            $ro->headers[Header::CACHE_CONTROL] = $setMaxAge;

            return;
        }

        if (str_contains($ro->headers[Header::CACHE_CONTROL], 'max-age')) {
            return;
        }

        $ro->headers[Header::CACHE_CONTROL] .= ', ' . $setMaxAge;
    }
}
