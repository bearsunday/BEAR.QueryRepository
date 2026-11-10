<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function explode;
use function implode;

final class SurrogateKeys
{
    /** @var list<string> */
    private array $surrogateKeys;
    private readonly UriTagInterface $uriTag;
    private readonly BaseUriTagInterface $baseUriTag;
    private readonly DependsOnTags $dependsOnTags;

    /** @var array<string, list<string>|null> */
    private array $declared = [];

    /** @param UriTagInterface|null $dependsOnUriTag what #[DependsOn] declarations expand with; the repository passes its own */
    public function __construct(AbstractUri $uri, string|null $baseTag = null, UriTagInterface|null $dependsOnUriTag = null)
    {
        $this->uriTag = new UriTag();
        $this->baseUriTag = new BaseUriTag($this->uriTag);
        $this->dependsOnTags = new DependsOnTags($dependsOnUriTag ?? $this->uriTag);
        $this->surrogateKeys = $baseTag === null ? [($this->uriTag)($uri)] : [($this->uriTag)($uri), $baseTag];
    }

    /**
     * Add the tags of an embedded resource, including its group tag so that a purge of its bare URI reaches the page
     */
    public function addTag(ResourceObject $ro): void
    {
        $this->embedded($ro);
        $this->surrogateKeys[] = ($this->uriTag)($ro->uri);
        $this->surrogateKeys[] = ($this->baseUriTag)($ro);
        if (array_key_exists(Header::SURROGATE_KEY, $ro->headers)) {
            $this->surrogateKeys = array_merge($this->surrogateKeys, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }
    }

    /**
     * Tags the embedded resource with what it declares in #[DependsOn], reading each URI once: a page's material is
     * classified before the page renders and rendered again as a request of its own
     *
     * @return bool whether the page depends on it
     *
     * @internal
     */
    public function embedded(ResourceObject $ro): bool
    {
        if (! DependsOnTags::hasRun($ro)) {
            return DependsOnTags::tagEmbedded($ro, null);
        }

        // (string) $ro->uri drops Stringable query values, so two requests could share one key
        $key = $ro::class . ' ' . ($this->uriTag)($ro->uri);
        if (array_key_exists($key, $this->declared)) {
            return DependsOnTags::tagEmbedded($ro, $this->declared[$key]);
        }

        $tags = $this->dependsOnTags->ran($ro);
        // A request that runs later, as it renders, may still add tags, so the answer is read again then
        if (! DependsOnTags::awaitsRequest($ro)) {
            $this->declared[$key] = $tags;
        }

        return DependsOnTags::tagEmbedded($ro, $tags);
    }

    public function setSurrogateHeader(ResourceObject $ro): void
    {
        $keys = $this->surrogateKeys;
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $keys = array_merge(explode(' ', $ro->headers[Header::SURROGATE_KEY]), $keys);
        }

        $ro->headers[Header::SURROGATE_KEY] = implode(' ', array_unique($keys));
    }
}
