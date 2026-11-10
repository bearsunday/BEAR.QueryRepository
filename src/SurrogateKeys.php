<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependsOnContext;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;

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
    private readonly BaseUriTagInterface $baseUriTag;
    private readonly string $uri;

    public function __construct(
        AbstractUri $uri,
        string|null $baseTag = null,
        private readonly SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
        $this->uri = (string) $uri;
        $this->uriTag = new UriTag();
        $this->baseUriTag = new BaseUriTag($this->uriTag);
        $this->surrogateKeys = $baseTag === null ? [($this->uriTag)($uri)] : [($this->uriTag)($uri), $baseTag];
    }

    /**
     * Add the tags of an embedded resource, including its group tag so that a purge of its bare URI reaches the page
     *
     * An embedded resource is recorded as a `depends_on` edge; the page's own tags are not.
     */
    public function addTag(ResourceObject $ro): void
    {
        $tags = [($this->uriTag)($ro->uri), ($this->baseUriTag)($ro)];
        if (array_key_exists(Header::SURROGATE_KEY, $ro->headers)) {
            $tags = array_merge($tags, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }

        $this->surrogateKeys = array_merge($this->surrogateKeys, $tags);
        if ((string) $ro->uri !== $this->uri) {
            $this->logger->event(new DependsOnContext($this->uri, (string) $ro->uri, array_values(array_unique($tags))));
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
