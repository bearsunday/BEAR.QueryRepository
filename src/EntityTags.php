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
 * The whole value must parse as a list of entity-tags or none are returned, since
 * half-reading a validator risks answering about content nobody asked for. A token with a
 * PSR-6 reserved character or `*` is dropped rather than looked up, since no setter could
 * have issued one.
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
