<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\DependsOnBodyNotRows;
use BEAR\QueryRepository\Exception\DependsOnRowKeyNotExists;
use BEAR\QueryRepository\Exception\DependsOnRowValueNotScalar;
use BEAR\QueryRepository\Exception\DependsOnTemplateWithoutVariable;
use BEAR\RepositoryModule\Annotation\DependsOn;
use BEAR\Resource\ResourceObject;
use ReflectionMethod;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_unique;
use function array_values;
use function explode;
use function get_debug_type;
use function implode;
use function is_array;
use function is_scalar;
use function method_exists;
use function preg_match_all;
use function preg_replace;
use function sprintf;

/**
 * Tags a resource with the URI each body row expands its #[DependsOn] template to
 *
 * @internal
 */
final readonly class RowDependency
{
    public function __construct(
        private UriTagInterface $uriTag,
    ) {
    }

    /** @return bool whether onGet() declares #[DependsOn] and its body was read */
    public function __invoke(ResourceObject $ro): bool
    {
        if ($ro->code !== 200 || $ro->body === null || ! method_exists($ro, 'onGet')) {
            return false;
        }

        $attributes = (new ReflectionMethod($ro, 'onGet'))->getAttributes(DependsOn::class);
        if ($attributes === []) {
            return false;
        }

        $where = (string) $ro->uri;
        $tags = [];
        foreach ($attributes as $attribute) {
            $template = $attribute->newInstance()->uri;
            $variables = self::variables($template);
            if ($variables === []) {
                throw new DependsOnTemplateWithoutVariable(sprintf("%s #[DependsOn(uri: '%s')]: the template has no variable", $where, $template));
            }

            if (! is_array($ro->body) || ! array_is_list($ro->body)) {
                throw new DependsOnBodyNotRows(sprintf("%s #[DependsOn(uri: '%s')]: body is %s, not a list of rows", $where, $template, get_debug_type($ro->body)));
            }

            $rows = [];
            /** @var mixed $row */
            foreach ($ro->body as $key => $row) {
                if (! is_array($row)) {
                    throw new DependsOnBodyNotRows(sprintf("%s #[DependsOn(uri: '%s')]: body[%s] is %s, not a row", $where, $template, (string) $key, get_debug_type($row)));
                }

                $values = self::rowValues($where, $template, (string) $key, $variables, $row);
                if ($values !== null) {
                    $rows[] = $values;
                }
            }

            if ($rows === []) {
                continue;
            }

            foreach (explode(' ', $this->uriTag->fromAssoc($template, $rows)) as $tag) {
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        if ($tags !== []) {
            $existing = isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
            $ro->headers[Header::SURROGATE_KEY] = implode(' ', array_values(array_unique(array_filter([...$existing, ...$tags], static fn (string $tag): bool => $tag !== ''))));
        }

        return true;
    }

    /**
     * @param list<string>            $variables
     * @param array<array-key, mixed> $row
     *
     * @return array<string, scalar>|null null when a variable in this row is null: the row names no dependency
     */
    private static function rowValues(string $where, string $template, string $key, array $variables, array $row): array|null
    {
        $values = [];
        foreach ($variables as $variable) {
            if (! array_key_exists($variable, $row)) {
                throw new DependsOnRowKeyNotExists(sprintf("%s #[DependsOn(uri: '%s')]: body[%s] has no '%s'", $where, $template, $key, $variable));
            }

            /** @var mixed $value */
            $value = $row[$variable];
            if ($value === null) {
                return null;
            }

            if (! is_scalar($value)) {
                throw new DependsOnRowValueNotScalar(sprintf("%s #[DependsOn(uri: '%s')]: body[%s]['%s'] is %s, not a scalar", $where, $template, $key, $variable, get_debug_type($value)));
            }

            $values[$variable] = $value;
        }

        return $values;
    }

    /** @return list<string> the RFC 6570 variable names a template expression declares */
    private static function variables(string $template): array
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
}
