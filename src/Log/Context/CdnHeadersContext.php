<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use Koriym\SemanticLogger\AbstractContext;

/**
 * Event: the CDN-facing headers a donut write or refresh put on the response.
 *
 * Read back from the response after the headers are final, so the log carries the
 * effect, not the request: the bound setter's default when the caller asked for no
 * sMaxAge, the resource's own value when it set one, and nothing when nothing gives
 * the CDN a lifetime - which is how an un-cacheable donut page stays off the CDN.
 *
 * `headers` holds the literal name => value pairs of every CDN-facing header this
 * package's setters manage that is present on the response (CDN-Cache-Control,
 * Surrogate-Control, Akamai-Cache-Control, Surrogate-Key, Edge-Cache-Tag), so the
 * flavor differences stay visible - Akamai, for one, renames Surrogate-Key to
 * Edge-Cache-Tag. `surrogateKeys` is the split key list from whichever key header is
 * present, the list a purge's tags must reach to drop this response at the edge.
 * A state-served hit replays the stored headers unchanged and emits no new event.
 */
final class CdnHeadersContext extends AbstractContext
{
    public const TYPE = 'cdn_headers';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/cdn_headers.json';

    /**
     * @param array<string, string> $headers
     * @param list<string>          $surrogateKeys
     */
    public function __construct(
        public readonly string $uri,
        public readonly array $headers,
        public readonly array $surrogateKeys,
    ) {
    }
}
