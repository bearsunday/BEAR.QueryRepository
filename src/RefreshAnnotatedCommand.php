<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCommand;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
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
use function substr;
use function uri_template;

final readonly class RefreshAnnotatedCommand implements CommandInterface
{
    public function __construct(
        private QueryRepositoryInterface $repository,
        private ResourceInterface $resource,
    ) {
    }

    #[Override]
    public function command(MethodInvocation $invocation, ResourceObject $ro): void
    {
        $method = $invocation->getMethod();
        $annotations = $method->getAnnotations();
        foreach ($annotations as $annotation) {
            $this->request($ro, $annotation);
        }
    }

    /**
     * One URI per element of each list given to an exploded variable (`{?id*}`, `?id={ids*}`), the product when there are several
     *
     * @return list<string>
     */
    private function getUris(ResourceObject $ro, AbstractCommand $annotation): array
    {
        $body = is_array($ro->body) ? $ro->body : [];
        $query = $body + $ro->uri->query;
        $variableSets = [$query];
        foreach (self::explodedNames($annotation->uri) as $name) {
            if (! isset($query[$name]) || ! is_array($query[$name]) || ! array_is_list($query[$name])) {
                continue;
            }

            $expanded = [];
            foreach ($variableSets as $variables) {
                /** @psalm-suppress MixedAssignment */
                foreach ($query[$name] as $value) {
                    $expanded[] = [$name => $value] + $variables;
                }
            }

            $variableSets = $expanded;
        }

        $uris = [];
        foreach ($variableSets as $variables) {
            $uris[] = uri_template($annotation->uri, $variables);
        }

        return $uris;
    }

    /** @return list<string> */
    private static function explodedNames(string $template): array
    {
        preg_match_all('/\{[+#.\/;?&]?([^}]+)\}/', $template, $matches);
        $names = [];
        foreach ($matches[1] as $varList) {
            foreach (explode(',', $varList) as $varSpec) {
                if (str_ends_with($varSpec, '*')) {
                    $names[] = substr($varSpec, 0, -1);
                }
            }
        }

        return array_values(array_unique($names));
    }

    private function request(ResourceObject $ro, object $annotation): void
    {
        if (! $annotation instanceof AbstractCommand) {
            return;
        }

        foreach ($this->getUris($ro, $annotation) as $uriString) {
            $uri = new Uri($uriString);
            if ($annotation instanceof Purge) {
                $this->repository->purge($uri);
            }

            if (! ($annotation instanceof Refresh)) {
                continue;
            }

            $this->repository->purge($uri);
            $refreshed = $this->resource->get((string) $uri);
            // A #[Cacheable] destination stored itself during the GET; a second put would purge the CDN again.
            if ($this->repository->get($uri) === null) {
                $this->repository->put($refreshed);
            }
        }
    }
}
