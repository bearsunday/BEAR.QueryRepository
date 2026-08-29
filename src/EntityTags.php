<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function strpbrk;
use function substr;
use function trim;

/**
 * The opaque-tags an `If-None-Match` field value contains, reduced to pool-key candidates
 *
 * Pool keys are bare opaque-tags, so quoted entity-tags (RFC 9110 §8.8.3), weak validators and
 * comma-separated lists are reduced to bare tokens. A comma inside a quoted opaque-tag is data,
 * not a list separator, and a bare legacy token (cached before ETags were quoted) passes through
 * unchanged. The whole field value must parse as a list of entity-tags: a value with an
 * unterminated quote or trailing garbage is rejected rather than salvaged, because half-reading a
 * validator is how a request gets answered about content nobody asked for.
 *
 * A token this server could never have issued is dropped, not looked up. Every setter produces
 * tags free of PSR-6 reserved characters (crc32 decimal, or the sanitized URI tag in dev), so a
 * token carrying one can never match - and handing it to the pool would turn a request header
 * into a thrown InvalidArgumentException. `*` is dropped for the same reason on top of not being
 * an opaque tag at all: RFC 9110 §13.1.2 gives it existence semantics this package does not
 * implement, so it must not be mistaken for a key.
 *
 * Its own class because two lookups need it and the parsing is the complicated half of both.
 */
final class EntityTags
{
    /** Entity-tag: optional weak indicator, then a quoted opaque-tag or a bare legacy token */
    private const ENTITY_TAG_PATTERN = '(?:W\/)?"[^"]*"|[^,"]+';

    /** PSR-6 reserves these in cache keys; no ETag setter emits them, so a token with one cannot match */
    private const PSR6_RESERVED = '{}()/\\@:';

    /** @return list<string> */
    public static function of(string $fieldValue): array
    {
        $pattern = '(?:' . self::ENTITY_TAG_PATTERN . ')';
        // \A/\z anchors (not ^/$) and OWS of SP/HTAB per RFC 9110; anything else rejects the whole field value
        if (! preg_match('/\A[ \t]*' . $pattern . '(?:[ \t]*,[ \t]*' . $pattern . ')*[ \t]*\z/', $fieldValue)) {
            return [];
        }

        $opaqueTags = [];
        // Tokenize as quoted entity-tags (optionally weak) or bare runs, so a comma inside quotes is not split
        preg_match_all('/' . $pattern . '/', $fieldValue, $entityTags);
        foreach ($entityTags[0] as $entityTag) {
            $entityTag = trim($entityTag);
            if (str_starts_with($entityTag, 'W/')) {
                $entityTag = substr($entityTag, 2);
            }

            $opaqueTag = trim($entityTag, '"');
            if ($opaqueTag !== '' && $opaqueTag !== '*' && strpbrk($opaqueTag, self::PSR6_RESERVED) === false) {
                $opaqueTags[] = $opaqueTag;
            }
        }

        return $opaqueTags;
    }
}
