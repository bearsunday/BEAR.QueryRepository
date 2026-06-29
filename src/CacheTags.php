<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function sprintf;

/**
 * Pure cache-tag computation shared by ResourceStorage (which saves with the tags)
 * and LoggableResourceStorage (which logs the same tags)
 *
 * Extracted so the decorator can report exactly the tags that were stored without
 * duplicating the derivation logic. Every method is a pure function of its inputs.
 */
final class CacheTags
{
    public function __construct(private readonly UriTagInterface $uriTag)
    {
    }

    /**
     * Tags for storing a resource: its URI tag and any surrogate keys
     *
     * The ETag is intentionally NOT an invalidation tag. The entry is purged by its URI tag
     * (deleteEtag / invalidateUri) and surrogate keys; no code path invalidates by ETag, and
     * HTTP 304 uses the separate ETag pool. Because the ETag is content-versioned, registering
     * it as a tag produced one non-volatile tag Set per content version that, under a
     * volatile-* eviction policy, is never reclaimed and never read — a memory leak (#180).
     *
     * @return list<string>
     */
    public function ofResource(ResourceObject $ro): array
    {
        $tags = [($this->uriTag)($ro->uri)];
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $tags = array_merge($tags, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }

        /** @var list<string> $uniqueTags */
        $uniqueTags = array_values(array_unique($tags));

        return $uniqueTags;
    }

    /**
     * The surrogate-key string a parent inherits when it depends on a child
     *
     * The child's URI tag, plus the child's own surrogate keys when present. Pure read:
     * it does not mutate the child (the dependency wiring is CacheDependency's concern).
     */
    public function childTags(ResourceObject $to): string
    {
        $childTags = ($this->uriTag)($to->uri);
        if (isset($to->headers[Header::SURROGATE_KEY])) {
            $childTags .= sprintf(' %s', $to->headers[Header::SURROGATE_KEY]);
        }

        return $childTags;
    }

    /**
     * Tags for storing an ETag entry: the surrogate keys plus the URI tag
     *
     * @return list<string>
     */
    public function ofEtag(AbstractUri $uri, string $surrogateKeys): array
    {
        $tags = $surrogateKeys !== '' ? explode(' ', $surrogateKeys) : [];
        $tags[] = ($this->uriTag)($uri);

        /** @var list<string> $uniqueTags */
        $uniqueTags = array_values(array_unique($tags));

        return $uniqueTags;
    }
}
