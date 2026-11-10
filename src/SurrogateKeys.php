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

    public function __construct(AbstractUri $uri, string|null $baseTag = null)
    {
        $this->uriTag = new UriTag();
        $this->baseUriTag = new BaseUriTag($this->uriTag);
        $this->dependsOnTags = new DependsOnTags($this->uriTag);
        $this->surrogateKeys = $baseTag === null ? [($this->uriTag)($uri)] : [($this->uriTag)($uri), $baseTag];
    }

    /**
     * Add the tags of an embedded resource, including its group tag so that a purge of its bare URI reaches the page
     */
    public function addTag(ResourceObject $ro): void
    {
        $this->dependsOnTags->embedded($ro);
        $this->surrogateKeys[] = ($this->uriTag)($ro->uri);
        $this->surrogateKeys[] = ($this->baseUriTag)($ro);
        if (array_key_exists(Header::SURROGATE_KEY, $ro->headers)) {
            $this->surrogateKeys = array_merge($this->surrogateKeys, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }
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
