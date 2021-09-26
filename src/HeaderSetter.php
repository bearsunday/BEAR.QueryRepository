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
    /** @var EtagSetter */
    private $etagSetter;

    public function __construct(EtagSetter $etagSetter)
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

    /**
     * @return void
     */
    private function setCacheControlMaxAge(ResourceObject $ro, int $age)
    {
        $setMaxAge = sprintf('max-age=%d', $age);
        $hasNoCacheControleHeader = ! isset($ro->headers['Cache-Control']);
        $headers = $ro->headers;
        if ($hasNoCacheControleHeader) {
            $ro->headers['Cache-Control'] = $setMaxAge;

            return;
        }

        $isMaxAgeAlreadyDefined = strpos($headers['Cache-Control'], 'max-age') !== false;
        if ($isMaxAgeAlreadyDefined) {
            return;
        }

        if (isset($ro->headers['Cache-Control'])) {
            $ro->headers['Cache-Control'] .= ', ' . $setMaxAge;
        }
    }
}
