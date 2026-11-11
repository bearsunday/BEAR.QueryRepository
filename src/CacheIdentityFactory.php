<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use ReflectionMethod;
use Stringable;

use function array_walk_recursive;
use function explode;
use function http_build_query;
use function ksort;
use function ltrim;
use function method_exists;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strtolower;
use function strtoupper;
use function trim;

/** @internal */
final class CacheIdentityFactory
{
    /** Scheme omission and separator collisions are part of the tag format. */
    public static function exactTag(AbstractUri $uri): string
    {
        $query = $uri->query;
        ksort($query);
        // http_build_query() drops objects that have no public properties, Stringable included.
        array_walk_recursive($query, static function (mixed &$value): void {
            if ($value instanceof Stringable) {
                $value = (string) $value;
            }
        });

        $uriKey = sprintf('%s_%s', $uri->path, http_build_query($query));

        return str_replace(['/', '?', '&'], '_', $uriKey);
    }

    /** Defaulted parameters are excluded; required parameters retain their supplied query spelling. */
    public static function groupTagFor(ResourceObject $ro, UriTagInterface $uriTag): string
    {
        if (! method_exists($ro, 'onGet')) {
            return self::groupTag(($uriTag)($ro->uri));
        }

        $query = $ro->uri->query;
        $baseQuery = [];
        foreach ((new ReflectionMethod($ro, 'onGet'))->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            $key = isset($query[$parameter->name]) ? $parameter->name : self::snakeName($parameter->name);
            if (! isset($query[$key])) {
                continue;
            }

            /** @psalm-suppress MixedAssignment */
            $baseQuery[$key] = $query[$key];
        }

        $baseUri = clone $ro->uri;
        $baseUri->query = $baseQuery;

        return self::groupTag(($uriTag)($baseUri));
    }

    /** Group tags must remain distinct from the exact tags invalidated before a write. */
    public static function groupTag(string $exactTag): string
    {
        return 'grp-' . $exactTag;
    }

    public static function snakeName(string $name): string
    {
        return ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_');
    }

    public static function storageKey(string $exactTag, string $type, ServerContextInterface $serverContext): string
    {
        return $type . $exactTag . self::vary($serverContext);
    }

    private static function vary(ServerContextInterface $serverContext): string
    {
        $xvary = $serverContext->get('X_VARY');
        if ($xvary === null) {
            return '';
        }

        $varys = explode(',', $xvary);
        $varyString = '';
        foreach ($varys as $vary) {
            $vary = trim($vary);
            if ($vary === '') {
                continue;
            }

            $phpVaryKey = sprintf('X_%s', strtoupper($vary));
            $value = $serverContext->get($phpVaryKey);
            if ($value !== null) {
                $varyString .= $value;
            }
        }

        return $varyString;
    }
}
