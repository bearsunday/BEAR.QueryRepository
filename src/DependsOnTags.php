<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use ArrayIterator;
use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Closure;
use Generator;
use IteratorAggregate;
use ReflectionMethod;
use Traversable;

use function array_diff_key;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function explode;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_iterable;
use function is_object;
use function sprintf;

/**
 * Adds to a resource's Surrogate-Key the tag of each URI its #[DependsOn] declarations expand to, and for a child that
 * ran inside its parent, the tags of the resources its #[ResourceParam] declarations read
 *
 * @internal
 */
final readonly class DependsOnTags
{
    private CacheTags $cacheTags;

    public function __construct(
        private UriTagInterface $uriTag,
        private CacheIdentityFactory $identityFactory = new CacheIdentityFactory(),
        private ResourceParamSources|null $resourceParamSources = null,
        CacheTags|null $cacheTags = null,
    ) {
        $this->cacheTags = $cacheTags ?? new CacheTags($uriTag, new BaseUriTag($uriTag));
    }

    /**
     * Enumerate only immediate values of the array-cast body, in order and with duplicates.
     * Do not execute requests, descend into children, or consume Traversable bodies.
     *
     * @return Generator<AbstractRequest>
     */
    public function requests(ResourceObject $ro): Generator
    {
        /** @var mixed $body */
        foreach ((array) $ro->body as $body) {
            if ($body instanceof AbstractRequest) {
                yield $body;
            }
        }
    }

    /** Tags the resource being stored with its onGet() declarations and the row classes in its body */
    public function __invoke(ResourceObject $ro): void
    {
        $this->addTags($ro, $this->declared($ro) ?? []);
    }

    /** @return list<ResourceObject> the resources its #[ResourceParam] declarations read: {@see ResourceParamSources} */
    public function resourceParamSources(ResourceObject $ro): array
    {
        return $this->resourceParamSources === null ? [] : ($this->resourceParamSources)($ro);
    }

    /**
     * Tags a child that ran inside its parent with what ran() finds for it
     *
     * @return bool whether its parent depends on it, as tagEmbedded() decides
     */
    public function embedded(ResourceObject $child): bool
    {
        return $this->tagEmbedded($child, $this->hasRun($child) ? $this->ran($child) : null);
    }

    /**
     * @param list<string>|null $tags what ran() found for the child
     *
     * @return bool whether its parent depends on it: it has a body, a declaration, an ETag or a Surrogate-Key
     */
    public function tagEmbedded(ResourceObject $child, array|null $tags): bool
    {
        $this->addTags($child, $tags ?? []);

        return $child->body !== null || $tags !== null || isset($child->headers[Header::ETAG]) || isset($child->headers[Header::SURROGATE_KEY]);
    }

    /**
     * Reads a resource that has run
     *
     * @param (Closure(ResourceObject): bool)|null $embedded reads a request it embeds; null reads it with embedded()
     *
     * @return list<string>|null what the resource declares and what its #[ResourceParam] read, with the tags of each
     *                           request it embeds and ran that it depends on; null when there is none
     */
    public function ran(ResourceObject $ro, Closure|null $embedded = null): array|null
    {
        $tags = $this->declared($ro);
        $read = $this->resourceParamSources?->tags($ro) ?? [];
        if ($read !== []) {
            $tags = [...$tags ?? [], ...$read];
        }

        $passedUp = $this->passedUp($ro, $embedded);

        return $passedUp === [] ? $tags : [...$tags ?? [], ...$passedUp];
    }

    /**
     * The tags of each request the resource embeds and ran that it depends on
     *
     * @param (Closure(ResourceObject): bool)|null $embedded reads a request it embeds; null reads it with embedded()
     *
     * @return list<string>
     */
    public function passedUp(ResourceObject $ro, Closure|null $embedded = null): array
    {
        $embedded ??= $this->embedded(...);
        $tags = [];
        foreach ($this->requests($ro) as $body) {
            if ($embedded($body->resourceObject)) {
                $tags = [...$tags, ...$this->cacheTags->tags($body->resourceObject)];
            }
        }

        return $tags;
    }

    /** A request that has not run yet, such as a hole, still has the default code and no body */
    public function hasRun(ResourceObject $ro): bool
    {
        return $ro->code !== 200 || $ro->body !== null;
    }

    /** @return list<string>|null null when neither onGet() nor a row class in the body declares */
    private function declared(ResourceObject $ro): array|null
    {
        $onClass = DependsOnTemplates::ofClass($ro::class);
        if ($onClass !== []) {
            throw new DependsOnNotExpandable((string) $ro->uri, DependsOnTemplates::declaration($onClass[0]), 'declared on a resource class');
        }

        $onGet = DependsOnTemplates::ofOnGet($ro);
        $tags = $onGet === [] ? null : $this->fromResult($ro, $onGet);
        $rows = RowClassWalk::tags($ro->body, $this->uriTag, (string) $ro->uri);
        if ($rows === null) {
            return $tags;
        }

        return [...$tags ?? [], ...$rows];
    }

    /**
     * Each variable is read from the row, then the body's top level, then the arguments rebuilt from the request query and the
     * defaults, past a value that cannot expand; with `key`, one any row holds is read from the rows alone: {@see DependsOn}. A row
     * that lacks a variable is skipped, and the declaration fails when every row does.
     *
     * @param list<DependsOn> $declarations
     *
     * @return list<string>
     */
    private function fromResult(ResourceObject $ro, array $declarations): array
    {
        $source = sprintf('%s onGet()', (string) $ro->uri);
        $onGet = new ReflectionMethod($ro, 'onGet');
        // #[ResourceParam] fills these from another resource whatever the query holds, so their value cannot be read back
        $bound = array_keys(ResourceParamSources::declared($onGet));
        $parameters = [];
        $arguments = [];
        foreach ($onGet->getParameters() as $parameter) {
            $parameters[] = $parameter->name;
            if (in_array($parameter->name, $bound, true)) {
                continue;
            }

            $arguments += $this->argument($parameter->name, $ro->uri->query);
            if (! array_key_exists($parameter->name, $arguments) && $parameter->isDefaultValueAvailable()) {
                /** @psalm-suppress MixedAssignment */
                $arguments[$parameter->name] = $parameter->getDefaultValue();
            }
        }

        $top = is_array($ro->body) ? $ro->body : [];
        $tags = [];
        foreach ($declarations as $dependsOn) {
            $template = $dependsOn->uri;
            $variables = DependsOnTemplates::variables($template);
            $rows = $this->rows($ro, $dependsOn->key);
            $fromRows = array_map(static fn (array $row): array => DependsOnTemplates::expandable($template, $row), $rows);
            $carried = $dependsOn->key === null ? [] : array_merge(...$fromRows);
            $outside = array_diff_key($top, $carried);
            $given = array_diff_key($arguments, $carried);
            $fallback = DependsOnTemplates::expandable($template, $outside) + DependsOnTemplates::expandable($template, $given);
            $rowless = $rows === [];
            if ($rowless && $dependsOn->key === null) {
                $rows = $fromRows = [[]];
            }

            $missing = '';
            $expanded = false;
            foreach ($rows as $i => $row) {
                $values = $fromRows[$i] + $fallback;
                $reason = $this->missing($variables, $values, $row + $outside + $given, $parameters, $bound);
                if ($reason !== null) {
                    $missing = $reason;
                    continue;
                }

                $expanded = true;
                $tags = [...$tags, ...DependsOnTemplates::tags($this->uriTag, $template, $values, $source)];
            }

            if (! $rowless && ! $expanded) {
                throw new DependsOnNotExpandable($source, DependsOnTemplates::declaration($template), $missing);
            }
        }

        return $tags;
    }

    /**
     * @param list<string>            $variables
     * @param array<array-key, mixed> $values     the values that can expand
     * @param array<array-key, mixed> $read       the values as read
     * @param list<string>            $parameters
     * @param list<string>            $bound
     *
     * @return string|null why a variable has no value; null when each has one
     */
    private function missing(array $variables, array $values, array $read, array $parameters, array $bound): string|null
    {
        foreach ($variables as $variable) {
            if (array_key_exists($variable, $values)) {
                continue;
            }

            if (array_key_exists($variable, $read)) {
                return sprintf("'%s' is %s", $variable, get_debug_type($read[$variable]));
            }

            if (! in_array($variable, $parameters, true)) {
                return sprintf("'%s' is not an onGet() parameter", $variable);
            }

            return in_array($variable, $bound, true) ? sprintf("'%s' is bound by #[ResourceParam]", $variable) : sprintf("no '%s'", $variable);
        }

        return null;
    }

    /**
     * The rows under the key, or the body itself without a key: each array or object of a list, or the array or object itself, read
     * as `(array)`. A Traversable that may not rewind is read once and put back as an ArrayIterator: {@see self::readOnce()}.
     *
     * @return list<array<array-key, mixed>>
     */
    private function rows(ResourceObject $ro, string|null $key): array
    {
        /** @var mixed $value */
        $value = $key === null ? $ro->body : (is_array($ro->body) && array_key_exists($key, $ro->body) ? $ro->body[$key] : null);
        if ($value instanceof Traversable && ! $value instanceof IteratorAggregate && ! $value instanceof ArrayIterator) {
            $value = $this->readOnce($value);
            if ($key === null) {
                $ro->body = $value;
            } elseif (is_array($ro->body)) {
                $ro->body[$key] = $value;
            }
        }

        $isList = is_array($value) ? array_is_list($value) : $value instanceof Traversable;
        if (! $isList && (is_array($value) || is_object($value))) {
            return [(array) $value];
        }

        $rows = [];
        /** @var mixed $row */
        foreach (is_iterable($value) ? $value : [] as $row) {
            if (is_array($row) || is_object($row)) {
                $rows[] = (array) $row;
            }
        }

        return $rows;
    }

    /**
     * @param Traversable<mixed, mixed> $iterator
     *
     * @return ArrayIterator<array-key, mixed> each value under the key it was yielded with, or as a list when a key repeats, as
     *                                         `yield from` gives, so that no row is lost
     */
    private function readOnce(Traversable $iterator): ArrayIterator
    {
        $keyed = [];
        $list = [];
        $unique = true;
        /**
         * @var array-key $key
         * @var mixed     $value
         */
        foreach ($iterator as $key => $value) {
            $unique = $unique && ! array_key_exists($key, $keyed);
            /** @psalm-suppress MixedAssignment */
            $keyed[$key] = $list[] = $value;
        }

        return new ArrayIterator($unique ? $keyed : $list);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed> the value under the parameter's name or its snake_case form; empty when the query has neither
     */
    private function argument(string $name, array $query): array
    {
        foreach ([$name, $this->identityFactory->snakeName($name)] as $key) {
            if (array_key_exists($key, $query)) {
                return [$name => $query[$key]];
            }
        }

        return [];
    }

    /** @param list<string> $tags */
    private function addTags(ResourceObject $ro, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $existing = isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
        $ro->headers[Header::SURROGATE_KEY] = (new TagSet([...$existing, ...$tags]))->toHeader();
    }
}
