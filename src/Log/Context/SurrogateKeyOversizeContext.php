<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Log\Context;

use BEAR\QueryRepository\Header;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\AbstractContext;

use function array_map;
use function explode;
use function max;
use function strlen;

/**
 * Event: the response's Surrogate-Key is over a Fastly limit, per key or for the whole header value
 *
 * Recorded only; the keys are stored and sent unchanged. Akamai's Edge-Cache-Tag is not measured.
 */
final class SurrogateKeyOversizeContext extends AbstractContext
{
    public const TYPE = 'surrogate_key_oversize';
    public const SCHEMA_URL = 'https://bearsunday.github.io/BEAR.QueryRepository/schemas/context/surrogate_key_oversize.json';
    private const FASTLY_MAX_KEY_BYTES = 1024;
    private const FASTLY_MAX_HEADER_BYTES = 16384;

    public function __construct(
        public readonly string $uri,
        public readonly int $headerBytes,
        public readonly int $longestKeyBytes,
    ) {
    }

    /** Null when the response carries no Surrogate-Key or one within both limits */
    public static function of(ResourceObject $ro): self|null
    {
        $surrogateKey = $ro->headers[Header::SURROGATE_KEY] ?? '';
        $headerBytes = strlen($surrogateKey);
        $longestKeyBytes = max(array_map(strlen(...), explode(' ', $surrogateKey)));
        if ($headerBytes <= self::FASTLY_MAX_HEADER_BYTES && $longestKeyBytes <= self::FASTLY_MAX_KEY_BYTES) {
            return null;
        }

        return new self((string) $ro->uri, $headerBytes, $longestKeyBytes);
    }
}
