<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use Override;

/**
 * Interceptor for full response caching on CQRS queries with #[CacheableResponse]
 *
 * Bound to query methods (onGet) of classes marked with #[CacheableResponse].
 *
 * @see \BEAR\RepositoryModule\Annotation\CacheableResponse
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html#event-driven-content
 * @internal
 */
final class DonutCacheableResponseInterceptor extends AbstractDonutCacheInterceptor
{
    #[Override]
    protected function put(DonutRepositoryInterface $donutRepository, ResourceObject $ro): ResourceObject
    {
        return $donutRepository->putStatic($ro, null, null);
    }
}
