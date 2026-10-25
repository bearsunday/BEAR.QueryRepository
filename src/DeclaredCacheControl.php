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
 * Mirrors `HttpCacheInterceptor`'s precedence, so this always matches what was written to
 * the header. `UnstorableGateInterface::reason()` exempts a header that still reads exactly
 * this value, since it's the attribute speaking, not a per-request value (#222).
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
