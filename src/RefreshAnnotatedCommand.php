<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\Annotation\Link;
use BEAR\Resource\Exception\UriException;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use BEAR\Resource\UriFactory;
use Ray\Aop\MethodInvocation;
use ReflectionMethod;

use function array_map;
use function array_unique;
use function array_values;
use function is_array;
use function method_exists;
use function parse_url;
use function str_starts_with;
use function uri_template;

use const PHP_URL_SCHEME;

/** @internal */
final readonly class RefreshAnnotatedCommand
{
    private const COLLECTION = 'collection';

    public function __construct(
        private ResourceInterface $resource,
        private CacheIdentityFactory $identityFactory,
        private DependsOnTemplates $templates,
        private UriFactory $uriFactory,
    ) {
    }

    /**
     * The URIs each #[Purge]/#[Refresh] of the method names, in declaration order, and whether it is a #[Refresh]; then the URI
     * each #[Link(rel: 'collection')] on the resource's onGet() names, purged only; an href that does not expand to a resource URI is
     * skipped
     *
     * @param MethodInvocation<object> $invocation
     *
     * @return list<array{list<Uri>, bool}>
     */
    public function declared(MethodInvocation $invocation, ResourceObject $ro): array
    {
        /** @var AbstractUri|null $uri a resource object taken from the injector has none */
        $uri = $ro->uri;
        $query = $uri === null ? $this->passedArguments($invocation) : $uri->query;
        $declared = [];
        foreach ($invocation->getMethod()->getAnnotations() as $annotation) {
            if (! $annotation instanceof Purge && ! $annotation instanceof Refresh) {
                continue;
            }

            $uris = array_map(static fn (string $uri): Uri => new Uri($uri), $this->uris($ro, $annotation->uri, $query));
            $declared[] = [$uris, $annotation instanceof Refresh];
        }

        $target = $invocation->getThis();
        if ($target instanceof ResourceObject) {
            foreach ($this->collections($target) as $href) {
                $declared[] = [$this->resourceUris($this->uris($ro, $href, $query)), false];
            }
        }

        return $declared;
    }

    /**
     * Called once every URI is purged, so a GET that throws leaves misses behind, not stale entries
     *
     * @param list<Uri> $uris
     */
    public function refresh(array $uris): void
    {
        foreach ($uris as $uri) {
            $this->resource->get((string) $uri);
        }
    }

    /**
     * The arguments the call passed, under each parameter's name and its snake_case form, as a request would carry them
     *
     * @param MethodInvocation<object> $invocation
     *
     * @return array<string, mixed>
     */
    private function passedArguments(MethodInvocation $invocation): array
    {
        $arguments = $invocation->getArguments();
        $query = [];
        foreach ($invocation->getMethod()->getParameters() as $parameter) {
            $position = $parameter->getPosition();
            if (! isset($arguments[$position])) {
                continue;
            }

            $query += [$parameter->name => $arguments[$position]];
            $query += [$this->identityFactory->snakeName($parameter->name) => $arguments[$position]];
        }

        return $query;
    }

    /**
     * One URI per element of each list given to an exploded query variable: {@see DependsOnTemplates::variableSets()}
     *
     * @param array<mixed> $query
     *
     * @return list<string>
     */
    private function uris(ResourceObject $ro, string $template, array $query): array
    {
        $body = is_array($ro->body) ? $ro->body : [];
        $uris = [];
        foreach ($this->templates->variableSets($template, $body + $query) as $variables) {
            $uris[] = uri_template($template, $variables);
        }

        return array_values(array_unique($uris));
    }

    /**
     * A path-only href takes the context scheme (#[ContextScheme]); a relative href without a leading slash names nothing
     *
     * @param list<string> $uris
     *
     * @return list<Uri>
     */
    private function resourceUris(array $uris): array
    {
        $resourceUris = [];
        foreach ($uris as $uri) {
            if (parse_url($uri, PHP_URL_SCHEME) === null && ! str_starts_with($uri, '/')) {
                continue;
            }

            try {
                $resourceUris[] = ($this->uriFactory)($uri);
            } catch (UriException) {
                continue;
            }
        }

        return $resourceUris;
    }

    /**
     * The href of each #[Link(rel: 'collection')] on onGet(), read as BEAR\Resource\Linker reads links
     *
     * @return list<string>
     */
    private function collections(ResourceObject $ro): array
    {
        if (! method_exists($ro, 'onGet')) {
            return [];
        }

        $hrefs = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getAttributes(Link::class) as $attribute) {
            $link = $attribute->newInstance();
            if ($link->rel === self::COLLECTION) {
                $hrefs[$link->href] = $link->href;
            }
        }

        return array_values($hrefs);
    }
}
