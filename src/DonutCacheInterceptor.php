<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

/**
 * Interceptor for donut caching on CQRS queries with #[DonutCache]
 *
 * Bound to query methods (onGet) or individual methods marked with #[DonutCache].
 *
 * @see \BEAR\RepositoryModule\Annotation\DonutCache
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#donut-cache
 * @internal
 */
final class DonutCacheInterceptor extends AbstractDonutCacheInterceptor
{
    #[Override]
    protected function put(DonutRepositoryInterface $donutRepository, ResourceObject $ro): ResourceObject
    {
        return $donutRepository->putDonut($ro, null);
    }
}
