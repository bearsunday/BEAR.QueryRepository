<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCommand;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Ray\Aop\MethodInvocation;

use function array_unique;
use function array_values;
use function is_array;
use function uri_template;

/** @internal */
final readonly class RefreshAnnotatedCommand
{
    public function __construct(
        private ResourceInterface $resource,
        private CacheIdentityFactory $identityFactory,
        private DependsOnTemplates $templates,
    ) {
    }

    /**
     * The URIs each #[Purge]/#[Refresh] of the method names, in declaration order, and whether it is a #[Refresh]
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

            $uris = [];
            foreach ($this->getUris($ro, $annotation, $query) as $uriString) {
                $uris[] = new Uri($uriString);
            }

            $declared[] = [$uris, $annotation instanceof Refresh];
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
    private function getUris(ResourceObject $ro, AbstractCommand $annotation, array $query): array
    {
        $body = is_array($ro->body) ? $ro->body : [];
        $uris = [];
        foreach ($this->templates->variableSets($annotation->uri, $body + $query) as $variables) {
            $uris[] = uri_template($annotation->uri, $variables);
        }

        return array_values(array_unique($uris));
    }
}
