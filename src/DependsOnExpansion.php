<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use Stringable;

use function array_diff_key;
use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_map;
use function array_merge;
use function get_debug_type;
use function in_array;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Expands the #[DependsOn] declarations of one onGet() result with what its rows, its body's top level and its arguments hold
 *
 * @internal
 */
final readonly class DependsOnExpansion
{
    /**
     * @param string                  $source     where the values are read, for DependsOnNotExpandable
     * @param array<array-key, mixed> $top        the body's top level
     * @param array<string, mixed>    $arguments  the onGet() arguments read back from the request
     * @param list<string>            $parameters the onGet() parameter names
     * @param list<string>            $bound      the parameters #[ResourceParam] binds, whose value is not read back
     */
    public function __construct(
        private UriTagInterface $uriTag,
        private DependsOnTemplates $templates,
        private string $source,
        private array $top,
        private array $arguments,
        private array $parameters,
        private array $bound,
    ) {
    }

    /**
     * Each variable is read from the row, then the body's top level, then the arguments, past a value that cannot expand; with
     * `key`, one any row holds is read from the rows alone: {@see DependsOn}. A row that lacks a variable is skipped, and the
     * declaration fails when every row does. Without a row, the top level and the arguments name a URI, or none when they cannot.
     *
     * @param list<array<array-key, mixed>> $rows
     *
     * @return list<string>
     */
    public function tags(DependsOn $dependsOn, array $rows): array
    {
        $template = $dependsOn->uri;
        $variables = $this->templates->variables($template);
        $exploded = $this->templates->explodedQueryNames($template);
        $fromRows = array_map(fn (array $row): array => $this->expandable($variables, $exploded, $row), $rows);
        $carried = $dependsOn->key === null ? [] : array_merge(...$fromRows);
        $outside = array_diff_key($this->top, $carried);
        $given = array_diff_key($this->arguments, $carried);
        $fallback = $this->expandable($variables, $exploded, $outside) + $this->expandable($variables, $exploded, $given);
        if ($rows === []) {
            $named = $dependsOn->key === null && $this->missing($variables, $fallback, $outside + $given) === null;

            return $named ? $this->templates->tags($this->uriTag, $template, $fallback, $this->source) : [];
        }

        $tags = [];
        $missing = '';
        $expanded = false;
        foreach ($rows as $i => $row) {
            $values = $fromRows[$i] + $fallback;
            $reason = $this->missing($variables, $values, $row + $outside + $given);
            if ($reason !== null) {
                $missing = $reason;
                continue;
            }

            $expanded = true;
            $tags = [...$tags, ...$this->templates->tags($this->uriTag, $template, $values, $this->source)];
        }

        if (! $expanded) {
            throw new DependsOnNotExpandable($this->source, $this->templates->declaration($template), $missing);
        }

        return $tags;
    }

    /**
     * @param list<string>            $variables
     * @param list<string>            $exploded  the variables a list is given to one element at a time
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed> the values the variables can expand with: a scalar, a Stringable or null, or a list of those under
     *                              an exploded query variable
     */
    private function expandable(array $variables, array $exploded, array $values): array
    {
        $expandable = [];
        foreach ($variables as $variable) {
            if (! array_key_exists($variable, $values)) {
                continue;
            }

            /** @var mixed $value */
            $value = $values[$variable];
            $elements = is_array($value) && array_is_list($value) && in_array($variable, $exploded, true) ? $value : [$value];
            if (array_filter($elements, fn (mixed $element): bool => ! $this->isValue($element)) === []) {
                /** @psalm-suppress MixedAssignment */
                $expandable[$variable] = $value;
            }
        }

        return $expandable;
    }

    private function isValue(mixed $value): bool
    {
        return $value === null || is_scalar($value) || $value instanceof Stringable;
    }

    /**
     * @param list<string>            $variables
     * @param array<array-key, mixed> $values    the values that can expand
     * @param array<array-key, mixed> $read      the values as read
     *
     * @return string|null why a variable has no value; null when each has one
     */
    private function missing(array $variables, array $values, array $read): string|null
    {
        foreach ($variables as $variable) {
            if (array_key_exists($variable, $values)) {
                continue;
            }

            if (array_key_exists($variable, $read)) {
                return sprintf("'%s' is %s", $variable, get_debug_type($read[$variable]));
            }

            if (! in_array($variable, $this->parameters, true)) {
                return sprintf("'%s' is not an onGet() parameter", $variable);
            }

            return in_array($variable, $this->bound, true) ? sprintf("'%s' is bound by #[ResourceParam]", $variable) : sprintf("no '%s'", $variable);
        }

        return null;
    }
}
