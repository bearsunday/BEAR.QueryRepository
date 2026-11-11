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
use function preg_match;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_replace;
use function strlen;
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
        $uriKey = sprintf('%s_%s', $uri->path, $this->sortedQuery($uri));

        return str_replace(['/', '?', '&'], '_', $uriKey);
    }

    /** Group tags must remain distinct from the exact tags invalidated before a write. */
    public function groupTag(string $exactTag): string
    {
        return 'grp-' . $exactTag;
    }

    /** Variants of one URI share K; the request's vary values tell their entries apart. */
    public function storageKey(string $locator, string $type): string
    {
        $vary = $this->vary();

        return $type . $locator . ($vary === '' ? '' : '.' . $this->digest($vary));
    }

    /**
     * The entry locator K that prefixes a stored ETag ("K.H"): 22 base64url characters
     *
     * Unlike the tag it keeps the scheme, the host and the separators, so distinct URIs get distinct entries.
     */
    public function locator(AbstractUri $uri): string
    {
        return $this->digest(sprintf('%s://%s%s?%s', $uri->scheme, $uri->host, $uri->path, $this->sortedQuery($uri)));
    }

    /** The K of a validator shaped like the ones stored here, null otherwise: no other client value may become a pool key */
    public function locatorOf(string $opaqueTag): string|null
    {
        return preg_match('/\A([A-Za-z0-9_-]{22})\./', $opaqueTag, $matches) === 1 ? $matches[1] : null;
    }

    /** The conversion BEAR.Resource applies when it binds a query to a parameter */
    public function snakeName(string $name): string
    {
        return ltrim(strtolower((string) preg_replace('/[A-Z]/', '_\0', $name)), '_');
    }

    private function sortedQuery(AbstractUri $uri): string
    {
        $query = $uri->query;
        ksort($query);
        // http_build_query() drops objects that have no public properties, Stringable included.
        array_walk_recursive($query, static function (mixed &$value): void {
            if ($value instanceof Stringable) {
                $value = (string) $value;
            }
        });

        return http_build_query($query);
    }

    private function digest(string $material): string
    {
        return rtrim(strtr(base64_encode(substr(hash('sha256', $material, true), 0, 16)), '+/', '-_'), '=');
    }

    /** The request's vary values, each behind its length: "ja", "mobile" and "jam", "obile" must not read the same. */
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
                $varyString .= strlen($value) . ':' . $value;
            }
        }

        return $varyString;
    }
}
