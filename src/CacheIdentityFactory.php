<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use Stringable;

use function array_walk_recursive;
use function base64_encode;
use function explode;
use function hash;
use function http_build_query;
use function ksort;
use function ltrim;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_replace;
use function strtolower;
use function strtoupper;
use function strtr;
use function substr;
use function trim;

/** @internal */
final readonly class CacheIdentityFactory
{
    public function __construct(private ServerContextInterface $serverContext = new GlobalServerContext())
    {
    }

    /** Scheme omission and separator collisions are part of the tag format. */
    public function exactTag(AbstractUri $uri): string
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

    /** Group tags must remain distinct from the exact tags invalidated before a write. */
    public function groupTag(string $exactTag): string
    {
        return 'grp-' . $exactTag;
    }

    public function storageKey(string $exactTag, string $type): string
    {
        return $type . $this->locator($exactTag);
    }

    /** The entry locator K that prefixes a stored ETag ("K.H"); never contains '.' */
    public function locator(string $exactTag): string
    {
        $digest = substr(hash('sha256', $exactTag . $this->vary(), true), 0, 16);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    /** The conversion BEAR.Resource applies when it binds a query to a parameter */
    public function snakeName(string $name): string
    {
        return ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_');
    }

    private function vary(): string
    {
        $xvary = $this->serverContext->get('X_VARY');
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
            $value = $this->serverContext->get($phpVaryKey);
            if ($value !== null) {
                $varyString .= $value;
            }
        }

        return $varyString;
    }
}
