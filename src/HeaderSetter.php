<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

use function is_int;
use function sprintf;
use function strpos;

final class HeaderSetter
{
    private \BEAR\QueryRepository\EtagSetterInterface $etagSetter;

    public function __construct(EtagSetterInterface $etagSetter)
    {
        $this->etagSetter = $etagSetter;
    }

    public function __invoke(ResourceObject $ro, ?int $concheControlMaxAge, ?HttpCache $httpCache): void
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

        $isMaxAgeAlreadyDefined = strpos($headers[Header::CACHE_CONTROL], 'max-age') !== false;
        if ($isMaxAgeAlreadyDefined) {
            return;
        }

        if (isset($ro->headers[Header::CACHE_CONTROL])) {
            $ro->headers[Header::CACHE_CONTROL] .= ', ' . $setMaxAge;
        }
    }
}
