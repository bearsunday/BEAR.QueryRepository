<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\TemplateLacksRequiredParameter;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\Exception\BadRequestException;
use BEAR\Resource\NamedParamMetasInterface;
use BEAR\Resource\RequiredParam;
use BEAR\Resource\ResourceInterface;
use Ray\Aop\MethodInvocation;

use function array_fill_keys;
use function array_key_exists;
use function explode;
use function is_callable;
use function parse_str;
use function parse_url;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function sprintf;
use function str_contains;
use function strrchr;
use function substr;
use function uri_template;

use const PHP_URL_QUERY;

/**
 * Checks that each #[Purge]/#[Refresh] template on a write method carries the required onGet() parameters of its destination
 *
 * @internal
 */
final class RefreshTemplateValidator
{
    /** @var array<string, true> */
    private array $validated = [];

    public function __construct(
        private readonly ResourceInterface $resource,
        private readonly NamedParamMetasInterface $namedParamMetas,
    ) {
    }

    /**
     * @param MethodInvocation<object> $invocation
     *
     * @throws TemplateLacksRequiredParameter
     */
    public function __invoke(MethodInvocation $invocation): void
    {
        $method = $invocation->getMethod();
        $methodName = $method->getDeclaringClass()->getName() . '::' . $method->getName();
        if (isset($this->validated[$methodName])) {
            return;
        }

        foreach ($method->getAnnotations() as $annotation) {
            if ($annotation instanceof Purge || $annotation instanceof Refresh) {
                $this->validate($methodName, $annotation);
            }
        }

        $this->validated[$methodName] = true;
    }

    private function validate(string $methodName, Purge|Refresh $annotation): void
    {
        $template = $annotation->uri;
        // A variable in the path picks the destination per request, so no class is known here.
        if (str_contains(preg_split('/\?|\{[?&]/', $template, 2)[0] ?? '', '{')) {
            return;
        }

        $uri = uri_template($template, array_fill_keys(self::variableNames($template), '0'));
        try {
            $destination = $this->resource->newInstance($uri);
        } catch (BadRequestException) {
            // Not a resource of this application, so there is no onGet() to check against.
            return;
        }

        $onGet = [$destination, 'onGet'];
        if (! is_callable($onGet)) {
            return;
        }

        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $missing = [];
        foreach (($this->namedParamMetas)($onGet) as $name => $param) {
            if (! $param instanceof RequiredParam || array_key_exists($name, $query) || array_key_exists(BaseUriTag::snakeName($name), $query)) {
                continue;
            }

            $missing[] = $name;
        }

        if ($missing === []) {
            return;
        }

        $attribute = substr((string) strrchr($annotation::class, '\\'), 1);

        throw new TemplateLacksRequiredParameter($methodName, sprintf("#[%s(uri: '%s')]", $attribute, $template), $missing);
    }

    /** @return list<string> */
    private static function variableNames(string $template): array
    {
        preg_match_all('/\{[+#.\/;?&]?([^}]+)\}/', $template, $matches);
        $names = [];
        foreach ($matches[1] as $varList) {
            foreach (explode(',', $varList) as $var) {
                $names[] = (string) preg_replace('/(:\d+|\*)$/', '', $var);
            }
        }

        return $names;
    }
}
