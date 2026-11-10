<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use SplObjectStorage;

use function get_object_vars;
use function is_array;
use function is_object;
use function sprintf;

/**
 * Walks a body through arrays and public properties for instances of a class that declares #[DependsOn]
 *
 * @internal
 */
final class RowClassWalk
{
    /** @var list<string>|null null until an instance of a declaring class is found */
    private array|null $tags = null;

    /** @var SplObjectStorage<object, null> */
    private SplObjectStorage $seen;

    private function __construct(
        private readonly UriTagInterface $uriTag,
        private readonly string $resource,
    ) {
        $this->seen = new SplObjectStorage();
    }

    /**
     * @param string $resource the URI of the resource whose body it is
     *
     * @return list<string>|null the tags the instances expand to; null when the body holds none
     */
    public static function tags(mixed $body, UriTagInterface $uriTag, string $resource): array|null
    {
        $walk = new self($uriTag, $resource);
        $walk->visit($body);

        return $walk->tags;
    }

    private function visit(mixed $value): void
    {
        if (is_array($value)) {
            /** @var mixed $item */
            foreach ($value as $item) {
                $this->visit($item);
            }

            return;
        }

        // An embedded request or resource is read as a dependency of its own, not as part of this body
        if (! is_object($value) || $value instanceof AbstractRequest || $value instanceof ResourceObject || $this->seen->contains($value)) {
            return;
        }

        $this->seen->attach($value);
        $properties = get_object_vars($value);
        $this->expand($value::class, $properties);
        /** @var mixed $property */
        foreach ($properties as $property) {
            $this->visit($property);
        }
    }

    /**
     * @param class-string            $class
     * @param array<array-key, mixed> $properties
     */
    private function expand(string $class, array $properties): void
    {
        $templates = DependsOnTemplates::ofClass($class);
        if ($templates === []) {
            return;
        }

        $this->tags ??= [];
        foreach ($templates as $template) {
            $tag = DependsOnTemplates::tag($this->uriTag, $template, $properties, sprintf('%s %s', $this->resource, $class));
            if ($tag !== null) {
                $this->tags[] = $tag;
            }
        }
    }
}
