<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use Override;

use function assert;
use function crc32;
use function gmdate;
use function is_array;
use function is_string;
use function serialize;
use function time;

final readonly class EtagSetter implements EtagSetterInterface
{
    public function __construct(
        private ResourceBodyEvaluator $evaluateBody = new ResourceBodyEvaluator(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null)
    {
        $time ??= time();
        if ($ro->code !== 200) {
            return;
        }

        $etag =  $this->getEtag($ro, $httpCache);
        // RFC 9110 §8.8.3: entity-tag is a DQUOTE-delimited opaque-tag
        $ro->headers[Header::ETAG] = '"' . $etag . '"';
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, $time);
    }

    public function getEtagByPartialBody(HttpCache $httpCacche, ResourceObject $ro): string
    {
        $etag = '';
        assert(is_array($ro->body));
        foreach ($httpCacche->etag as $bodyEtag) {
            if (isset($ro->body[$bodyEtag]) && is_string($ro->body[$bodyEtag])) {
                $etag .= $ro->body[$bodyEtag];
            }
        }

        return $etag;
    }

    /**
     * The state the validator stands for: the rendered view, or the body when nothing rendered
     *
     * A value entry is stored without rendering, so the view is null there and the body is what
     * the entry holds. Falling back to it keeps the validator tied to the state - a view-derived
     * tag over a null view would be the same string for every state of every such resource.
     */
    public function getEtagByEitireView(ResourceObject $ro): string
    {
        // A body may still hold embedded requests, which refuse to serialize; the evaluator
        // materializes the runs that already happened, as copies, without touching this response.
        return $ro::class . serialize($ro->view ?? ($this->evaluateBody)($ro->body));
    }

    /**
     * Return crc32 encoded Etag
     *
     * Is crc32 enough for Etag ?
     *
     * @see https://cloud.google.com/storage/docs/hashes-etags
     */
    private function getEtag(ResourceObject $ro, HttpCache|null $httpCache = null): string
    {
        $etag = $httpCache instanceof HttpCache && $httpCache->etag ? $this->getEtagByPartialBody($httpCache, $ro) : $this->getEtagByEitireView($ro);

        return (string) crc32($ro::class . $etag . (string) $ro->uri);
    }
}
