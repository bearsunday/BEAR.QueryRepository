<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\PutDonutContext;
use BEAR\QueryRepository\Log\Context\RefreshDonutContext;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function assert;
use function explode;
use function trim;

final readonly class DonutRepository implements DonutRepositoryInterface
{
    public function __construct(
        private QueryRepositoryInterface $queryRepository,
        private HeaderSetter $headerSetter,
        private ResourceStorageInterface $resourceStorage,
        private ResourceInterface $resource,
        private CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter,
        private SemanticLoggerInterface $logger,
        private DonutRendererInterface $renderer,
    ) {
    }

    #[Override]
    public function get(ResourceObject $ro): ResourceObject|null
    {
        $maybeState = $this->queryRepository->get($ro->uri);
        if ($maybeState instanceof ResourceState) {
            $ro->headers = $maybeState->headers;
            $ro->view = $maybeState->view;

            return $ro;
        }

        return $this->refreshDonut($ro);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function putStatic(ResourceObject $ro, int|null $ttl = null, int|null $sMaxAge = null): ResourceObject
    {
        $this->logger->event(new PutDonutContext((string) $ro->uri, $ttl, $sMaxAge));
        $keys = new SurrogateKeys($ro->uri);
        $keys->addTag($ro);
        $headerKeys = $this->getHeaderKeys($ro);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $sMaxAge, true);
        $donut->render($ro, $this->renderer);
        $this->setHeaders($keys, $ro, $sMaxAge);
        // delete
        $this->resourceStorage->invalidateTags([(new UriTag())($ro->uri)]);
        // save content cache and donut
        $this->saveView($ro, $sMaxAge);
        $this->resourceStorage->saveDonut($ro->uri, $donut, $ttl, $headerKeys);

        return $ro;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function putDonut(ResourceObject $ro, int|null $donutTtl): ResourceObject
    {
        $this->logger->event(new PutDonutContext((string) $ro->uri, $donutTtl, null));
        $keys = new SurrogateKeys($ro->uri);
        $keyArrays = $this->getHeaderKeys($ro);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $donutTtl, false);
        $donut->render($ro, $this->renderer);
        $keys->setSurrogateHeader($ro);
        // delete
        $this->resourceStorage->invalidateTags([(new UriTag())($ro->uri)]);
        // save donut
        $this->resourceStorage->saveDonut($ro->uri, $donut, $donutTtl, $keyArrays);

        return $ro;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function purge(AbstractUri $uri): void
    {
        $this->queryRepository->purge($uri);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function invalidateTags(array $tags): void
    {
        $this->resourceStorage->invalidateTags($tags);
    }

    private function refreshDonut(ResourceObject $ro): ResourceObject|null
    {
        $donut = $this->resourceStorage->getDonut($ro->uri);
        if (! $donut instanceof ResourceDonut) {
            $this->logger->event(new CacheMissContext('donut'));

            return null;
        }

        $this->logger->event(new CacheHitContext('donut'));
        $this->logger->event(new RefreshDonutContext((string) $ro->uri));
        $donut->refresh($this->resource, $ro);
        if (! $donut->isCacheble) {
            return $ro;
        }

        ($this->headerSetter)($ro, $donut->ttl, null);
        // mark refreshed by resource static; the marker stays inside the DQUOTEs to keep the entity-tag valid
        $ro->headers[Header::ETAG] = '"' . trim($ro->headers[Header::ETAG], '"') . 'r"';
        ($this->cdnCacheControlHeaderSetter)($ro, $donut->ttl);
        $this->saveView($ro, $donut->ttl);

        return $ro;
    }

    private function saveView(ResourceObject $ro, int|null $ttl): bool
    {
        assert(isset($ro->headers[Header::ETAG]));
        $surrogateKeys = $ro->headers[Header::SURROGATE_KEY] ?? '';
        $this->resourceStorage->saveEtag($ro->uri, $ro->headers[Header::ETAG], $surrogateKeys, $ttl);

        return $this->resourceStorage->saveDonutView($ro, $ttl);
    }

    private function setHeaders(SurrogateKeys $keys, ResourceObject $ro, int|null $sMaxAge): void
    {
        $keys->setSurrogateHeader($ro);
        ($this->cdnCacheControlHeaderSetter)($ro, $sMaxAge);
        ($this->headerSetter)($ro, 0, null);
    }

    /** @return list<string> */
    public function getHeaderKeys(ResourceObject $ro): array
    {
        return isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
    }
}
