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
 * The Cache-Control value `#[HttpCache]`/`#[NoHttpCache]` declares for a resource, and whether
 * a response's own code overrode it to something unstorable
 *
 * A declared value is exempt from `reason()`'s check - it is the attribute's own
 * client-facing directive, not a per-request value. A runtime `private`/`no-store` outside
 * that declared value is refused, since this package is a shared cache: whatever it stores
 * is served to every later visitor.
 *
 * @internal
 */
final readonly class DeclaredCacheControl
{
    private const REASON = 'unstorable';

    private function attribute(ResourceObject $ro): HttpCache|NoHttpCache|null
    {
        $class = new ReflectionClass($ro);
        $attributes = $class->getAttributes(HttpCache::class);
        if ($attributes === []) {
            $attributes = $class->getAttributes(NoHttpCache::class);
        }

        return isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }

    /**
     * Declared for one visitor and still storable by a browser: a CDN must not be given a lifetime for it, as
     * CDN-Cache-Control makes the CDN ignore Cache-Control (RFC 9213 §2.2). A declared no-store is not, since no cache
     * downstream keeps it.
     */
    public function isPrivate(ResourceObject $ro): bool
    {
        $declared = $this->attribute($ro);

        return $declared instanceof HttpCache && $declared->isPrivate && ! $declared->noStore;
    }

    /**
     * The reason this response must not be written to the shared cache, or null when storable
     *
     * @return 'unstorable'|null
     */
    public function reason(ResourceObject $ro): string|null
    {
        $declared = $this->attribute($ro);
        $declared = $declared === null ? null : (string) $declared;
        foreach ($ro->headers as $name => $cacheControl) {
            if (strtolower($name) !== strtolower(Header::CACHE_CONTROL) || $cacheControl === $declared) {
                continue;
            }

            $lower = strtolower($cacheControl);
            if (str_contains($lower, 'no-store') || str_contains($lower, 'private')) {
                return self::REASON;
            }
        }

        return null;
    }
}
