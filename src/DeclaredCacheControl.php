<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\UnstorableDeclaration;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\Resource\ResourceObject;
use ReflectionClass;

use function array_key_exists;
use function str_contains;
use function strtolower;

/**
 * The Cache-Control value `#[HttpCache]`/`#[NoHttpCache]` declares for a resource, and whether a write may store it
 *
 * @internal
 */
final class DeclaredCacheControl
{
    /** @var array<class-string, HttpCache|NoHttpCache|null> */
    private array $declared = [];

    /**
     * Decide for a response as the resource and its renderer returned it, before the write adds its own headers
     *
     * A runtime `private`/`no-store` makes the response unstorable: this package is a shared cache, and whatever it
     * stores is served to every later visitor.
     *
     * @throws UnstorableDeclaration When the declared value itself has `private` or `no-store`.
     */
    public function storability(ResourceObject $ro): Storability
    {
        $declaredValue = $this->cacheControl($ro);
        if ($declaredValue !== null && $this->forbidsSharing($declaredValue)) {
            throw new UnstorableDeclaration((string) $ro->uri, $declaredValue);
        }

        foreach ($ro->headers as $name => $cacheControl) {
            if (strtolower($name) === strtolower(Header::CACHE_CONTROL) && $this->forbidsSharing($cacheControl)) {
                return Storability::Unstorable;
            }
        }

        return Storability::Shared;
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

    public function attribute(ResourceObject $ro): HttpCache|NoHttpCache|null
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

    private function forbidsSharing(string $cacheControl): bool
    {
        $lower = strtolower($cacheControl);

        return str_contains($lower, 'no-store') || str_contains($lower, 'private');
    }
}
