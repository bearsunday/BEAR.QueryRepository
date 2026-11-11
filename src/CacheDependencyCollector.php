<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependencySkippedContext;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use Generator;
use Koriym\SemanticLogger\SemanticLoggerInterface;

use function explode;

/** @internal */
final class CacheDependencyCollector
{
    /** @return Generator<AbstractRequest> */
    public static function requests(ResourceObject $ro): Generator
    {
        /** @var mixed $body */
        foreach ((array) $ro->body as $body) {
            if ($body instanceof AbstractRequest) {
                yield $body;
            }
        }
    }

    /** Null materialKeys renders children; donut collection must only inspect children that already ran. */
    public static function collect(
        ResourceObject $ro,
        DependsOnTags $dependsOnTags,
        CacheDependencyInterface $cacheDependency,
        SemanticLoggerInterface $logger,
        SurrogateKeys|null $materialKeys = null,
    ): void {
        $dependsOnTags($ro);
        foreach (self::requests($ro) as $request) {
            if ($materialKeys === null) {
                (string) $request;
            }

            $child = $request->resourceObject;
            $depends = $materialKeys === null ? $dependsOnTags->embedded($child) : $materialKeys->embedded($child);
            if (! $depends) {
                $logger->event(DependencySkippedContext::of($ro, $child));

                continue;
            }

            $cacheDependency->depends($ro, $child);
        }
    }

    /**
     * @param list<string>|null $declaredTags
     *
     * @return list<string>
     */
    public static function tags(ResourceObject $ro, UriTagInterface $uriTag, BaseUriTagInterface $baseUriTag, array|null $declaredTags = null): array
    {
        $ownTags = [($uriTag)($ro->uri), ($baseUriTag)($ro)];
        $declaredTags ??= isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];

        return [...$ownTags, ...$declaredTags];
    }
}
