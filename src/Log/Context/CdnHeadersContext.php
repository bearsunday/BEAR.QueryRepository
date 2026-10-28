<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use JsonSerializable;
use Koriym\SemanticLogger\AbstractContext;
use Override;
use stdClass;

/**
 * Event: the CDN-facing headers a donut write or refresh put on the response.
 *
 * Read back from the response after the headers are final, so the log carries the
 * effect, not the request: the bound setter's default when the caller asked for no
 * sMaxAge, the resource's own value when it set one, and nothing when nothing gives
 * the CDN a lifetime - which is how an un-cacheable donut page is meant to stay off
 * the CDN, and how a response DeclaredCacheControl::reason() refuses (its own code set
 * private/no-store) is too: every CDN-facing header is stripped, so this records none.
 * A no-store declared by #[HttpCache] is not refused. What the CDN then does
 * is outside this record.
 *
 * `headers` holds the literal name => value pairs of every CDN-facing header this
 * package's setters manage that is present on the response (CDN-Cache-Control,
 * Surrogate-Control, Akamai-Cache-Control, Surrogate-Key, Edge-Cache-Tag), so the
 * flavor differences stay visible - Akamai, for one, renames Surrogate-Key to
 * Edge-Cache-Tag. `surrogateKeys` is the split, merged key list from every key header
 * present, the list a purge's tags must reach to drop this response at the edge.
 * A state-served hit replays the stored headers unchanged and emits no new event.
 */
final class CdnHeadersContext extends AbstractContext implements JsonSerializable
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

    /**
     * An empty `headers` map is a PHP array indistinguishable from an empty list, which
     * json_encode() renders `[]` - the schema requires an object (`{}`) even then.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'uri' => $this->uri,
            'headers' => $this->headers === [] ? new stdClass() : $this->headers,
            'surrogateKeys' => $this->surrogateKeys,
        ];
    }
}
