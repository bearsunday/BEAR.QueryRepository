<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnNotExpandable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use ReflectionAttribute;
use ReflectionClass;
use Stringable;

use function array_is_list;
use function array_key_exists;
use function array_unique;
use function array_values;
use function explode;
use function get_debug_type;
use function is_array;
use function is_scalar;
use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_ends_with;
use function strpos;
use function substr;
use function uri_template;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

/**
 * The URI templates #[DependsOn] declares, and the values #[DependsOn], #[Purge] and #[Refresh] expand theirs with
 *
 * @internal
 */
final class DependsOnTemplates
{
    /** @var array<class-string, list<string>> */
    private static array $onClass = [];

    /** @var array<class-string, list<DependsOn>> */
    private static array $onGet = [];

    /**
     * @param class-string $class
     *
     * @return list<string> the templates declared on the class and its parents
     */
    public static function ofClass(string $class): array
    {
        if (isset(self::$onClass[$class])) {
            return self::$onClass[$class];
        }

        $templates = [];
        for ($ref = new ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
            $templates = [...$templates, ...self::uris($ref->getAttributes(DependsOn::class))];
        }

        return self::$onClass[$class] = array_values(array_unique($templates));
    }

    /** @return list<DependsOn> the declarations on onGet(), including an onGet() the class overrides, each URI and key once */
    public static function ofOnGet(ResourceObject $ro): array
    {
        if (isset(self::$onGet[$ro::class])) {
            return self::$onGet[$ro::class];
        }

        $declarations = [];
        for ($ref = new ReflectionClass($ro); $ref !== false && $ref->hasMethod('onGet'); $ref = $ref->getParentClass()) {
            foreach ($ref->getMethod('onGet')->getAttributes(DependsOn::class) as $attribute) {
                $dependsOn = $attribute->newInstance();
                $declarations[$dependsOn->uri . "\n" . (string) $dependsOn->key] ??= $dependsOn;
            }
        }

        return self::$onGet[$ro::class] = array_values($declarations);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string> the tag of each URI the template expands to, one per element of a list given to an exploded query variable
     */
    public static function tags(UriTagInterface $uriTag, string $template, array $values, string $source): array
    {
        $tags = [];
        foreach (self::variableSets($template, $values) as $variables) {
            $tag = self::tag($uriTag, $template, $variables, $source);
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
    public static function variableSets(string $template, array $values): array
    {
        $sets = [$values];
        foreach (self::explodedQueryNames($template) as $name) {
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
     * @param array<array-key, mixed> $values
     *
     * @return string|null the tag of the URI the template expands to; null when a variable is null, which names no URI
     */
    public static function tag(UriTagInterface $uriTag, string $template, array $values, string $source): string|null
    {
        $variables = [];
        foreach (self::variables($template) as $variable) {
            if (! array_key_exists($variable, $values)) {
                throw new DependsOnNotExpandable($source, self::declaration($template), sprintf("no '%s'", $variable));
            }

            /** @var mixed $value */
            $value = $values[$variable];
            if ($value === null) {
                return null;
            }

            if ($value instanceof Stringable) {
                $value = (string) $value;
            }

            if (! is_scalar($value)) {
                throw new DependsOnNotExpandable($source, self::declaration($template), sprintf("'%s' is %s", $variable, get_debug_type($value)));
            }

            $variables[$variable] = $value;
        }

        return $uriTag(new Uri(uri_template($template, $variables)));
    }

    /** @return list<string> the RFC 6570 variable names in the template */
    public static function variables(string $template): array
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

    public static function declaration(string $template): string
    {
        return sprintf("#[DependsOn(uri: '%s')]", $template);
    }

    /**
     * @param list<ReflectionAttribute<DependsOn>> $attributes
     *
     * @return list<string>
     */
    private static function uris(array $attributes): array
    {
        $uris = [];
        foreach ($attributes as $attribute) {
            $uris[] = $attribute->newInstance()->uri;
        }

        return $uris;
    }

    /** @return list<string> */
    private static function explodedQueryNames(string $template): array
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
