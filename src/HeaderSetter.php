<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

use function is_int;
use function sprintf;
use function str_contains;

final class HeaderSetter
{
    public function __construct(
        private EtagSetterInterface $etagSetter,
    ) {
    }

    public function __invoke(ResourceObject $ro, int|null $concheControlMaxAge, HttpCache|null $httpCache): void
    {
        ($this->etagSetter)($ro, null, $httpCache);

        if (is_int($concheControlMaxAge) && $concheControlMaxAge > 0) {
            $this->setCacheControlMaxAge($ro, $concheControlMaxAge);
        }
    }

    private function setCacheControlMaxAge(ResourceObject $ro, int $age): void
    {
        $setMaxAge = sprintf('max-age=%d', $age);
        $hasNoCacheControleHeader = ! isset($ro->headers[Header::CACHE_CONTROL]);
        $headers = $ro->headers;
        if ($hasNoCacheControleHeader) {
            $ro->headers[Header::CACHE_CONTROL] = $setMaxAge;

            return;
        }

        $isMaxAgeAlreadyDefined = str_contains($headers[Header::CACHE_CONTROL], 'max-age');
        if ($isMaxAgeAlreadyDefined) {
            return;
        }

        if (isset($ro->headers[Header::CACHE_CONTROL])) {
            $ro->headers[Header::CACHE_CONTROL] .= ', ' . $setMaxAge;
        }
    }
}
