<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Generator;
use ReflectionMethod;

use function array_key_exists;
use function explode;
use function in_array;
use function sprintf;

/**
 * Adds to a resource's Surrogate-Key the tag of each URI its #[DependsOn] declarations expand to
 *
 * @internal
 */
final readonly class DependsOnTags
{
    public function __construct(
        private UriTagInterface $uriTag,
    ) {
    }

    /**
     * Enumerate only immediate values of the array-cast body, in order and with duplicates.
     * Do not execute requests, descend into children, or consume Traversable bodies.
     *
     * @return Generator<AbstractRequest>
     */
    public static function requests(ResourceObject $ro): Generator
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
        self::addTags($ro, $this->declared($ro) ?? []);
    }

    /**
     * Tags a child that ran inside its parent, adding what the requests it embeds and ran declare
     *
     * @return bool whether its parent depends on it: a declaration was found, or it carries an ETag or a Surrogate-Key
     */
    public function embedded(ResourceObject $child): bool
    {
        return self::tagEmbedded($child, $this->ran($child));
    }

    /**
     * @param list<string>|null $tags what ran() found for the child
     *
     * @return bool whether its parent depends on it: a declaration was found, or it carries an ETag or a Surrogate-Key
     */
    public static function tagEmbedded(ResourceObject $child, array|null $tags): bool
    {
        self::addTags($child, $tags ?? []);

        return $tags !== null || isset($child->headers[Header::ETAG]) || isset($child->headers[Header::SURROGATE_KEY]);
    }

    /** @return list<string>|null the tags the resource and the requests it embeds and ran declare; null when none declares */
    public function ran(ResourceObject $ro): array|null
    {
        if (! self::hasRun($ro)) {
            return null;
        }

        $tags = $this->declared($ro);
        foreach (self::requests($ro) as $body) {
            $embedded = $this->ran($body->resourceObject);
            if ($embedded !== null) {
                $tags = [...$tags ?? [], ...$embedded];
            }
        }

        return $tags;
    }

    /** A request that has not run yet, such as a hole, still has the default code and no body */
    public static function hasRun(ResourceObject $ro): bool
    {
        return $ro->code !== 200 || $ro->body !== null;
    }

    /** Whether a request it embeds, at any depth, has not run yet: what that request declares is known only once it runs */
    public static function awaitsRequest(ResourceObject $ro): bool
    {
        foreach (self::requests($ro) as $body) {
            if (! self::hasRun($body->resourceObject) || self::awaitsRequest($body->resourceObject)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null null when neither onGet() nor a row class in the body declares */
    private function declared(ResourceObject $ro): array|null
    {
        $onClass = DependsOnTemplates::ofClass($ro::class);
        if ($onClass !== []) {
            throw new DependsOnNotExpandable((string) $ro->uri, DependsOnTemplates::declaration($onClass[0]), 'declared on a resource class');
        }

        $onGet = DependsOnTemplates::ofOnGet($ro);
        $tags = $onGet === [] ? null : $this->fromArguments($ro, $onGet);
        $rows = RowClassWalk::tags($ro->body, $this->uriTag, (string) $ro->uri);
        if ($rows === null) {
            return $tags;
        }

        return [...$tags ?? [], ...$rows];
    }

    /**
     * The call's arguments are not on the write path, so they are rebuilt from the request query and the defaults
     *
     * @param list<string> $templates
     *
     * @return list<string>
     */
    private function fromArguments(ResourceObject $ro, array $templates): array
    {
        $source = sprintf('%s onGet()', (string) $ro->uri);
        $parameters = [];
        $arguments = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getParameters() as $parameter) {
            $parameters[] = $parameter->name;
            $arguments += self::argument($parameter->name, $ro->uri->query);
            if (! array_key_exists($parameter->name, $arguments) && $parameter->isDefaultValueAvailable()) {
                /** @psalm-suppress MixedAssignment */
                $arguments[$parameter->name] = $parameter->getDefaultValue();
            }
        }

        $tags = [];
        foreach ($templates as $template) {
            foreach (DependsOnTemplates::variables($template) as $variable) {
                if (! in_array($variable, $parameters, true)) {
                    throw new DependsOnNotExpandable($source, DependsOnTemplates::declaration($template), sprintf("'%s' is not an onGet() parameter", $variable));
                }
            }

            $tag = DependsOnTemplates::tag($this->uriTag, $template, $arguments, $source);
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed> the value under the parameter's name or its snake_case form; empty when the query has neither
     */
    private static function argument(string $name, array $query): array
    {
        foreach ([$name, BaseUriTag::snakeName($name)] as $key) {
            if (array_key_exists($key, $query)) {
                return [$name => $query[$key]];
            }
        }

        return [];
    }

    /** @param list<string> $tags */
    private static function addTags(ResourceObject $ro, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $existing = isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
        $ro->headers[Header::SURROGATE_KEY] = (new TagSet([...$existing, ...$tags]))->toHeader();
    }
}
