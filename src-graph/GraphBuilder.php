<?php

declare(strict_types=1);

namespace BEAR\QueryRepositoryGraph;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheableResponse;
use BEAR\RepositoryModule\Annotation\DonutCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\RepositoryModule\Annotation\RefreshCache;
use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\ResourceObject;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

use function array_filter;
use function file_get_contents;
use function getcwd;
use function preg_match;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function usort;

/**
 * Builds the declared cache graph: one record per resource, its declarations nested under it
 *
 * Every edge starts at the resource that declares it, so a record carries no `from`.
 * The record's shape is `docs/schemas/graph/resource.json`.
 *
 * @psalm-type Target = array{uri: string, template?: string, implicit?: string}
 * @psalm-type Method = array{source: string, cacheableResponse?: true, embed?: list<array{rel: string, uri: string, template?: string}>, refresh?: list<Target>, purge?: list<Target>}
 * @psalm-type Node = array{uri: string, source: string, cacheable?: array{expiry: string, expirySecond: int, expiryAt: string, update: bool, type: string}, donutCache?: true, cacheableResponse?: true, httpCache?: string, methods?: array<string, Method>, dynamic?: list<string>}
 */
final class GraphBuilder
{
    /** Methods the command interceptors bind to: they refresh the resource's own onGet */
    private const WRITE_METHODS = ['onPut', 'onPost', 'onPatch', 'onDelete'];

    private readonly string $cwd;

    public function __construct(
        private readonly UriConverter $uriConverter = new UriConverter(),
        string|null $cwd = null,
    ) {
        $this->cwd = str_replace('\\', '/', $cwd ?? (string) getcwd());
    }

    /**
     * @param array<class-string<ResourceObject>, ReflectionClass<ResourceObject>> $classes
     *
     * @return array{namespaces: array<string, string>, nodes: list<Node>}
     */
    public function build(array $classes): array
    {
        $namespace = null;
        $nodes = [];
        foreach ($classes as $ref) {
            $converted = $this->uriConverter->fromClass($ref->getName());
            if ($converted === null) {
                continue;
            }

            $namespace ??= $converted['namespace'];
            if ($converted['namespace'] !== $namespace) {
                throw new RuntimeException(sprintf('One application per graph: found %s and %s', $namespace, $converted['namespace']));
            }

            $nodes[] = $this->node($ref, $converted['uri']);
        }

        usort($nodes, static fn (array $a, array $b): int => $a['uri'] <=> $b['uri']);

        return ['namespaces' => $namespace === null ? [] : ['self' => $namespace], 'nodes' => $nodes];
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     *
     * @return Node
     */
    private function node(ReflectionClass $ref, string $uri): array
    {
        $node = ['uri' => $uri, 'source' => $this->source((string) $ref->getFileName(), (int) $ref->getStartLine())];
        $cacheable = $this->attribute($ref, Cacheable::class);
        if ($cacheable !== null) {
            $node['cacheable'] = [
                'expiry' => $cacheable->expiry,
                'expirySecond' => $cacheable->expirySecond,
                'expiryAt' => $cacheable->expiryAt,
                'update' => $cacheable->update,
                'type' => $cacheable->type,
            ];
        }

        if ($this->attribute($ref, DonutCache::class) !== null) {
            $node['donutCache'] = true;
        }

        $classCacheableResponse = $this->attribute($ref, CacheableResponse::class) !== null;
        if ($classCacheableResponse) {
            $node['cacheableResponse'] = true;
        }

        $cacheControl = $ref->getAttributes(AbstractCacheControl::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($cacheControl !== []) {
            $node['httpCache'] = (string) $cacheControl[0]->newInstance();
        }

        $methods = [];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! str_starts_with($method->getName(), 'on') || $method->getDeclaringClass()->getName() === ResourceObject::class) {
                continue;
            }

            $declared = $this->method($method, $uri, $cacheable !== null, $classCacheableResponse);
            if ($declared !== null) {
                $methods[$method->getName()] = $declared;
            }
        }

        if ($methods !== []) {
            $node['methods'] = $methods;
        }

        $dynamic = $this->dynamic((string) $ref->getFileName());
        if ($dynamic !== []) {
            $node['dynamic'] = $dynamic;
        }

        return $node;
    }

    /** @return Method|null null when the method declares nothing the cache acts on */
    private function method(ReflectionMethod $method, string $uri, bool $cacheable, bool $classCacheableResponse): array|null
    {
        $embed = [];
        foreach ($method->getAttributes(Embed::class) as $attribute) {
            $instance = $attribute->newInstance();
            $embed[] = ['rel' => $instance->rel] + $this->uriConverter->resolve($instance->src, $uri);
        }

        // The command interceptors refresh the resource's own onGet before any #[Refresh]/#[Purge].
        $refresh = [];
        $implicit = $this->implicitRefresh($method, $cacheable, $classCacheableResponse);
        if ($implicit !== null) {
            $refresh[] = ['uri' => $uri, 'implicit' => $implicit];
        }

        foreach ($method->getAttributes(Refresh::class) as $attribute) {
            $refresh[] = $this->uriConverter->resolve($attribute->newInstance()->uri, $uri);
        }

        $purge = [];
        foreach ($method->getAttributes(Purge::class) as $attribute) {
            $purge[] = $this->uriConverter->resolve($attribute->newInstance()->uri, $uri);
        }

        $declared = [];
        if ($method->getAttributes(CacheableResponse::class) !== []) {
            $declared['cacheableResponse'] = true;
        }

        $declared += array_filter(['embed' => $embed, 'refresh' => $refresh, 'purge' => $purge]);
        if ($declared === []) {
            return null;
        }

        return ['source' => $this->source((string) $method->getFileName(), (int) $method->getStartLine())] + $declared;
    }

    /** @return string|null the attribute that makes the write refresh its own resource */
    private function implicitRefresh(ReflectionMethod $method, bool $cacheable, bool $classCacheableResponse): string|null
    {
        if ($method->getAttributes(RefreshCache::class) !== []) {
            return 'RefreshCache';
        }

        $isWrite = false;
        foreach (self::WRITE_METHODS as $prefix) {
            $isWrite = $isWrite || str_starts_with($method->getName(), $prefix);
        }

        if (! $isWrite) {
            return null;
        }

        if ($cacheable) {
            return 'Cacheable';
        }

        if ($classCacheableResponse || $method->getAttributes(CacheableResponse::class) !== []) {
            return 'CacheableResponse';
        }

        return null;
    }

    /**
     * Source-text hints that the declarations may not tell the whole story
     *
     * A hint is a reason to read the code; its absence proves nothing.
     *
     * @return list<string>
     */
    private function dynamic(string $file): array
    {
        $code = (string) file_get_contents($file);
        $hints = [];
        if (preg_match('/SURROGATE_KEY|surrogate-key/i', $code)) {
            $hints[] = 'surrogate-key';
        }

        if (preg_match('/\b(?:ResourceInject|ResourceInterface)\b/', $code)) {
            $hints[] = 'resource-request';
        }

        return $hints;
    }

    private function source(string $file, int $line): string
    {
        // Forward slashes on every OS, so a source reads and matches the same on Windows.
        $file = str_replace('\\', '/', $file);
        $relative = str_starts_with($file, $this->cwd . '/') ? substr($file, strlen($this->cwd) + 1) : $file;

        return "{$relative}:{$line}";
    }

    /**
     * @param ReflectionClass<ResourceObject> $ref
     * @param class-string<T>                 $name
     *
     * @return T|null
     *
     * @template T of object
     */
    private function attribute(ReflectionClass $ref, string $name): object|null
    {
        $attributes = $ref->getAttributes($name);

        return $attributes === [] ? null : $attributes[0]->newInstance();
    }
}
