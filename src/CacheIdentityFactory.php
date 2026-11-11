<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use Stringable;

use function array_walk_recursive;
use function explode;
use function http_build_query;
use function ksort;
use function sprintf;
use function str_replace;
use function strtoupper;
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
        return $type . $exactTag . $this->vary();
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
