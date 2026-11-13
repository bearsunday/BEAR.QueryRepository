<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\LinkType;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function array_filter;
use function array_map;
use function assert;
use function explode;
use function implode;
use function rawurldecode;
use function rawurlencode;
use function str_contains;
use function str_starts_with;
use function strpos;
use function substr;

/**
 * The URI of an embedded request and the links it carries, as the one string a hit builds the same request from
 *
 * @internal
 */
final class EmbedReference
{
    /** Separates the URI from the links. A URI holds no '#': its query is percent-encoded. */
    private const LINKS = '#link:';

    public static function of(AbstractRequest $request): string
    {
        $links = array_map(static fn (LinkType $link): string => $link->type . '=' . rawurlencode($link->key), $request->links);

        return $request->toUri() . ($links === [] ? '' : self::LINKS . implode('&', $links));
    }

    public static function request(ResourceInterface $resource, string $reference): AbstractRequest
    {
        $parts = explode(self::LINKS, $reference, 2);
        $request = $resource->uri($parts[0]);
        assert($request instanceof AbstractRequest);
        foreach (array_filter(explode('&', $parts[1] ?? '')) as $link) {
            $key = rawurldecode(substr($link, (int) strpos($link, '=') + 1));
            match (true) {
                str_starts_with($link, LinkType::SELF_LINK . '=') => $request->linkSelf($key),
                str_starts_with($link, LinkType::NEW_LINK . '=') => $request->linkNew($key),
                default => $request->linkCrawl($key),
            };
        }

        return $request;
    }

    /** The resource the reference names. One without links needs no request built: get() reads it. */
    public static function get(ResourceInterface $resource, string $reference): ResourceObject
    {
        return str_contains($reference, self::LINKS) ? self::request($resource, $reference)() : $resource->get($reference);
    }
}
