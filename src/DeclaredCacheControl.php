<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;
use ReflectionClass;

/**
 * The Cache-Control value `#[HttpCache]` or `#[NoHttpCache]` declares for a resource
 *
 * Mirrors `HttpCacheInterceptor`'s own precedence (`#[HttpCache]` first, `#[NoHttpCache]`
 * fallback), so the value this returns is always what that interceptor actually wrote to the
 * header. `UnstorableGateInterface::reason()` exempts a header that still reads exactly this
 * value: the attribute speaking - a client-facing directive - not a per-request value a method
 * or a render produced. Shared by `QueryRepository` and every `DonutRepository` write/refresh
 * path so `#[NoHttpCache]` and the donut kinds are exempted the same way `#[HttpCache]` already
 * is on the plain `#[Cacheable]` path (issue #222).
 */
final readonly class DeclaredCacheControl
{
    public function __invoke(ResourceObject $ro): string|null
    {
        $class = new ReflectionClass($ro);
        $attributes = $class->getAttributes(HttpCache::class);
        if ($attributes === []) {
            $attributes = $class->getAttributes(NoHttpCache::class);
        }

        if (! isset($attributes[0])) {
            return null;
        }

        $cacheControl = $attributes[0]->newInstance();

        return (string) $cacheControl;
    }
}
