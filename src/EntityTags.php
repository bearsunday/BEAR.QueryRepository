<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function substr;
use function trim;

/**
 * The opaque-tags an `If-None-Match` field value contains
 *
 * Pool keys are bare opaque-tags, so quoted entity-tags (RFC 9110 §8.8.3), weak validators and
 * comma-separated lists are reduced to bare tokens. A comma inside a quoted opaque-tag is data,
 * not a list separator, and a bare legacy token (cached before ETags were quoted) passes through
 * unchanged. The whole field value must parse as a list of entity-tags: a value with an
 * unterminated quote or trailing garbage is rejected rather than salvaged, because half-reading a
 * validator is how a request gets answered about content nobody asked for.
 *
 * Its own class because two lookups need it and the parsing is the complicated half of both.
 */
final class EntityTags
{
    /** Entity-tag: optional weak indicator, then a quoted opaque-tag or a bare legacy token */
    private const ENTITY_TAG_PATTERN = '(?:W\/)?"[^"]*"|[^,"]+';

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
            if ($opaqueTag !== '') {
                $opaqueTags[] = $opaqueTag;
            }
        }

        return $opaqueTags;
    }
}
