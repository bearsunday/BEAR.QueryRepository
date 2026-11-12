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
use Override;
use Ray\Aop\MethodInvocation;

use function array_is_list;
use function array_unique;
use function array_values;
use function explode;
use function is_array;
use function preg_match_all;
use function str_ends_with;
use function strpos;
use function substr;
use function uri_template;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

final readonly class RefreshAnnotatedCommand implements CommandInterface
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        private ResourceInterface $resource,
        private CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
    ) {
    }

    #[Override]
    public function command(MethodInvocation $invocation, ResourceObject $ro): void
    {
        $method = $invocation->getMethod();
        /** @var AbstractUri|null $uri a resource object taken from the injector has none */
        $uri = $ro->uri;
        $query = $uri === null ? $this->passedArguments($invocation) : $uri->query;
        foreach ($method->getAnnotations() as $annotation) {
            $this->request($ro, $annotation, $query);
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
     * One URI per element of each list given to an exploded query variable (`{?id*}`, `?id={ids*}`), the product when there are several
     *
     * @param array<mixed> $query
     *
     * @return list<string>
     */
    private function getUris(ResourceObject $ro, AbstractCommand $annotation, array $query): array
    {
        $body = is_array($ro->body) ? $ro->body : [];
        $query = $body + $query;
        $variableSets = [$query];
        foreach ($this->explodedQueryNames($annotation->uri) as $name) {
            if (isset($query[$name]) && is_array($query[$name]) && array_is_list($query[$name])) {
                $variableSets = $this->fanOut($variableSets, $name, $query[$name]);
            }
        }

        $uris = [];
        foreach ($variableSets as $variables) {
            $uris[] = uri_template($annotation->uri, $variables);
        }

        return array_values(array_unique($uris));
    }

    /**
     * @param list<array<mixed>> $variableSets
     * @param list<mixed>        $values
     *
     * @return list<array<mixed>>
     */
    private function fanOut(array $variableSets, string $name, array $values): array
    {
        $expanded = [];
        foreach ($variableSets as $variables) {
            /** @psalm-suppress MixedAssignment */
            foreach ($values as $value) {
                // A null would drop the variable, and the bare URI's group tag reaches every variant.
                if ($value === null) {
                    continue;
                }

                $expanded[] = [$name => $value] + $variables;
            }
        }

        return $expanded;
    }

    /** @return list<string> */
    private function explodedQueryNames(string $template): array
    {
        preg_match_all('/\{([+#.\/;?&]?)([^}]+)\}/', $template, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $queryStart = strpos($template, '?');
        $names = [];
        foreach ($matches as $match) {
            $operator = $match[1][0];
            $inQuery = $operator === '?' || $operator === '&' || ($operator === '' && $queryStart !== false && $match[0][1] > $queryStart);
            if (! $inQuery) {
                continue;
            }

            foreach (explode(',', $match[2][0]) as $varSpec) {
                if (str_ends_with($varSpec, '*')) {
                    $names[] = substr($varSpec, 0, -1);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<mixed> $query */
    private function request(ResourceObject $ro, object $annotation, array $query): void
    {
        if (! $annotation instanceof Purge && ! $annotation instanceof Refresh) {
            return;
        }

        $uris = [];
        foreach ($this->getUris($ro, $annotation, $query) as $uriString) {
            $uri = new Uri($uriString);
            $this->repository->purge($uri);
            $uris[] = $uri;
        }

        if (! $annotation instanceof Refresh) {
            return;
        }

        // Every URI is purged before the first GET, so a GET that throws leaves misses behind, not stale entries.
        foreach ($uris as $uri) {
            $this->resource->get((string) $uri);
        }
    }
}
