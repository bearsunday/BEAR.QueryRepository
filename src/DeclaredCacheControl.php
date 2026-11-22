<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;
use ReflectionClass;

use function array_key_exists;
use function str_contains;
use function strtolower;

/**
 * The Cache-Control value `#[HttpCache]`/`#[NoHttpCache]` declares for a resource, and whether
 * a response's own code overrode it to something unstorable
 *
 * A declared value is exempt from `storability()`'s check - it is the attribute's own
 * client-facing directive, not a per-request value. A runtime `private`/`no-store` outside
 * that declared value is refused, since this package is a shared cache: whatever it stores
 * is served to every later visitor.
 *
 * @internal
 */
final class DeclaredCacheControl
{
    /** @var array<class-string, HttpCache|NoHttpCache|null> */
    private array $declared = [];

    /** Decide for a response as the resource and its renderer returned it, before the write adds its own headers */
    public function storability(ResourceObject $ro): Storability
    {
        $declaredValue = $this->cacheControl($ro);
        foreach ($ro->headers as $name => $cacheControl) {
            if (strtolower($name) !== strtolower(Header::CACHE_CONTROL) || $cacheControl === $declaredValue) {
                continue;
            }

            $lower = strtolower($cacheControl);
            if (str_contains($lower, 'no-store') || str_contains($lower, 'private')) {
                return Storability::Unstorable;
            }
        }

        return $this->declaredStorability($this->attribute($ro));
    }

    /** Decide for a response rebuilt from a stored entry, by the declaration alone */
    public function stored(ResourceObject $ro): Storability
    {
        return $this->declaredStorability($this->attribute($ro));
    }

    /** The Cache-Control value the attribute declares, as HttpCacheInterceptor writes it */
    public function cacheControl(ResourceObject $ro): string|null
    {
        $declared = $this->attribute($ro);

        return $declared === null ? null : (string) $declared;
    }

    public function httpCache(ResourceObject $ro): HttpCache|null
    {
        $declared = $this->attribute($ro);

        return $declared instanceof HttpCache ? $declared : null;
    }

    private function declaredStorability(HttpCache|NoHttpCache|null $declared): Storability
    {
        $isPrivate = $declared instanceof NoHttpCache || ($declared instanceof HttpCache && $declared->isPrivate);

        return $isPrivate ? Storability::DeclaredPrivate : Storability::Shared;
    }

    private function attribute(ResourceObject $ro): HttpCache|NoHttpCache|null
    {
        if (array_key_exists($ro::class, $this->declared)) {
            return $this->declared[$ro::class];
        }

        $class = new ReflectionClass($ro);
        $attributes = $class->getAttributes(HttpCache::class);
        if ($attributes === []) {
            $attributes = $class->getAttributes(NoHttpCache::class);
        }

        return $this->declared[$ro::class] = isset($attributes[0]) ? $attributes[0]->newInstance() : null;
    }
}
