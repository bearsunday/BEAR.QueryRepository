<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;
use ReflectionClass;

use function str_contains;
use function strtolower;

/**
 * The Cache-Control value `#[HttpCache]` or `#[NoHttpCache]` declares for a resource, and
 * whether a response's own code overrode it to something unstorable
 *
 * Mirrors `HttpCacheInterceptor`'s precedence, so `__invoke()` always matches what was written
 * to the header. This package is a shared cache in the sense of RFC 9111: whatever it stores
 * is served to every later visitor, so reason() refuses a write whose Cache-Control reads
 * private or no-store (matched case-insensitively, RFC 7230 §3.2/RFC 9111 §5.2) and was not
 * set by that same declaration - the attribute's own client-facing directive is exempt,
 * since `#[Cacheable]` combined with `#[HttpCache(noStore: true)]` still stores server-side
 * while telling the client not to.
 */
final readonly class DeclaredCacheControl
{
    private const REASON = 'unstorable';

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

    /**
     * The reason this response must not be written to the shared cache, or null when storable
     *
     * @return 'unstorable'|null
     */
    public function reason(ResourceObject $ro): string|null
    {
        $name = Header::findKey($ro->headers, Header::CACHE_CONTROL);
        $cacheControl = $name === null ? '' : $ro->headers[$name];
        if ($cacheControl === '' || $cacheControl === $this($ro)) {
            return null;
        }

        $lower = strtolower($cacheControl);
        if (str_contains($lower, 'no-store') || str_contains($lower, 'private')) {
            return self::REASON;
        }

        return null;
    }
}
