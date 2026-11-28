<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function assert;

/**
 * Interceptor for HTTP Cache-Control header with #[HttpCache] or #[NoHttpCache]
 *
 * Bound to onGet methods of classes marked with #[HttpCache] or #[NoHttpCache]. #[HttpCache] sets a 200 response,
 * #[NoHttpCache] every response.
 *
 * @see \BEAR\RepositoryModule\Annotation\HttpCache
 * @see \BEAR\RepositoryModule\Annotation\NoHttpCache
 * @see https://bearsunday.github.io/manuals/1.0/en/cache.html
 * @internal
 */
final readonly class HttpCacheInterceptor implements MethodInterceptor
{
    public function __construct(
        private DeclaredCacheControl $declaredCacheControl,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        $target = $invocation->getThis();
        $cacheControl = $target instanceof ResourceObject ? $this->declaredCacheControl->attribute($target) : null;
        $ro = $invocation->proceed();
        assert($ro instanceof ResourceObject);
        if ($cacheControl !== null && ($ro->code === 200 || $cacheControl instanceof NoHttpCache)) {
            $ro->headers[Header::findKey($ro->headers, Header::CACHE_CONTROL) ?? Header::CACHE_CONTROL] = (string) $cacheControl;
        }

        return $ro;
    }
}
