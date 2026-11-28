<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Uri;
use Stringable;

use function array_is_list;
use function array_unique;
use function array_values;
use function explode;
use function is_array;
use function preg_match_all;
use function preg_replace;
use function str_ends_with;
use function strpos;
use function substr;
use function uri_template;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

/**
 * RFC 6570 URI template variable parsing and expansion shared by #[DependsOn], #[Purge] and #[Refresh]
 *
 * @internal
 */
final class UriTemplates
{
    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string> the tag of each URI the template expands to, one per element of a list given to an exploded query variable
     */
    public function tags(UriTagInterface $uriTag, string $template, array $values): array
    {
        $tags = [];
        foreach ($this->variableSets($template, $values) as $variables) {
            $tag = $this->tag($uriTag, $template, $variables);
            if ($tag !== null) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * One set per element of each list given to an exploded query variable (`{?id*}`, `?id={ids*}`), the product when there are
     * several. A null element names no URI and is skipped: the bare URI's group tag reaches every variant.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<array<array-key, mixed>>
     */
    public function variableSets(string $template, array $values): array
    {
        $sets = [$values];
        foreach ($this->explodedQueryNames($template) as $name) {
            if (! isset($values[$name]) || ! is_array($values[$name]) || ! array_is_list($values[$name])) {
                continue;
            }

            $expanded = [];
            foreach ($sets as $set) {
                /** @psalm-suppress MixedAssignment */
                foreach ($values[$name] as $value) {
                    if ($value !== null) {
                        $expanded[] = [$name => $value] + $set;
                    }
                }
            }

            $sets = $expanded;
        }

        return $sets;
    }

    /**
     * @param array<array-key, mixed> $values each variable of the template holds a scalar, a Stringable or null: {@see DependsOnExpansion}
     *
     * @return string|null the tag of the URI the template expands to; null when a variable is null, which names no URI
     */
    public function tag(UriTagInterface $uriTag, string $template, array $values): string|null
    {
        $variables = [];
        foreach ($this->variables($template) as $variable) {
            /** @var scalar|Stringable|null $value */
            $value = $values[$variable];
            if ($value === null) {
                return null;
            }

            $variables[$variable] = $value instanceof Stringable ? (string) $value : $value;
        }

        return $uriTag(new Uri(uri_template($template, $variables)));
    }

    /** @return list<string> the RFC 6570 variable names in the template */
    public function variables(string $template): array
    {
        preg_match_all('/\{[+#.\/;?&]?([^}]+)\}/', $template, $matches);
        $variables = [];
        foreach ($matches[1] as $expression) {
            foreach (explode(',', $expression) as $spec) {
                $variables[] = (string) preg_replace('/(\*|:\d+)$/', '', $spec);
            }
        }

        return $variables;
    }

    /** @return list<string> the query variables a list is given to one element at a time (`{?id*}`, `?id={ids*}`) */
    public function explodedQueryNames(string $template): array
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
}
