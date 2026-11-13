<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function array_merge;
use function array_unique;
use function array_values;
use function explode;
use function implode;

final class SurrogateKeys
{
    /** @var list<string> */
    private array $surrogateKeys;
    private readonly UriTagInterface $uriTag;
    private readonly UriTag $keyTag;
    private readonly CacheTags $cacheTags;
    private readonly DependsOnTags $dependsOnTags;

    /** @var array<string, list<string>|null> */
    private array $declared = [];

    public function __construct(AbstractUri $uri, string|null $baseTag = null, UriTagInterface|null $uriTag = null, CacheTags|null $cacheTags = null, DependsOnTags|null $dependsOnTags = null)
    {
        $this->uriTag = $uriTag ?? new UriTag();
        $this->keyTag = new UriTag();
        $this->cacheTags = $cacheTags ?? new CacheTags($this->uriTag, new BaseUriTag($this->uriTag));
        $this->dependsOnTags = $dependsOnTags ?? new DependsOnTags($this->uriTag, cacheTags: $this->cacheTags);
        $this->surrogateKeys = $baseTag === null ? [($this->uriTag)($uri)] : [($this->uriTag)($uri), $baseTag];
    }

    /**
     * Add the tags of an embedded resource, including its group tag so that a purge of its bare URI reaches the page
     */
    public function addTag(ResourceObject $ro): void
    {
        $this->embedded($ro);
        $this->surrogateKeys = [...$this->surrogateKeys, ...$this->cacheTags->tags($ro)];
    }

    /**
     * Tags the embedded resource with what it declares in #[DependsOn], what its #[ResourceParam] read and what the
     * requests it embeds and ran pass up. What it declares is read once per URI: a page's material is read before the
     * page renders, while its body is as onGet() left it, and read again as the request of its own the page renders.
     * What the requests below pass up is read each time and kept with what was found before, as those requests may run
     * in between: a hole runs its own as the page renders.
     *
     * @return bool whether the page depends on it
     *
     * @internal
     */
    public function embedded(ResourceObject $ro): bool
    {
        if (! $this->dependsOnTags->hasRun($ro)) {
            return $this->dependsOnTags->tagEmbedded($ro, null);
        }

        // The default UriTag keeps Stringable query values apart; (string) $ro->uri, or an application's tagger, may not
        $key = $ro::class . ' ' . ($this->keyTag)($ro->uri);
        if (! array_key_exists($key, $this->declared)) {
            $this->declared[$key] = $this->dependsOnTags->ran($ro, $this->embedded(...));

            return $this->dependsOnTags->tagEmbedded($ro, $this->declared[$key]);
        }

        $passedUp = $this->dependsOnTags->passedUp($ro, $this->embedded(...));
        if ($passedUp !== []) {
            $this->declared[$key] = array_values(array_unique([...$this->declared[$key] ?? [], ...$passedUp]));
        }

        return $this->dependsOnTags->tagEmbedded($ro, $this->declared[$key]);
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
