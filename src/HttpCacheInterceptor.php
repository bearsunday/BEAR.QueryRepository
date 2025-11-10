<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function assert;

/**
 * Interceptor for HTTP Cache-Control header with #[HttpCache] or #[NoHttpCache]
 *
 * Bound to onGet methods of classes marked with #[HttpCache] or #[NoHttpCache].
 * Sets the Cache-Control header based on attribute configuration for HTTP caching.
 *
 * @see \BEAR\RepositoryModule\Annotation\HttpCache
 * @see \BEAR\RepositoryModule\Annotation\NoHttpCache
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html
 */
final class HttpCacheInterceptor implements MethodInterceptor
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        $class = $invocation->getMethod()->getDeclaringClass();
        $attributes = $class->getAttributes(HttpCache::class);
        if (empty($attributes)) {
            $attributes = $class->getAttributes(NoHttpCache::class);
        }

        $cacheControl = isset($attributes[0]) ? $attributes[0]->newInstance() : null;
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        if ($ro->code === 200 && $cacheControl instanceof AbstractCacheControl) {
            $ro->headers[Header::CACHE_CONTROL] = (string) $cacheControl;
        }

        return $ro;
    }
}
