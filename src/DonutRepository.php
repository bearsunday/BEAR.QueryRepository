<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function assert;

final class DonutRepository implements DonutRepositoryInterface
{
    private ResourceStorageInterface $resourceStorage;
    private HeaderSetter $headerSetter;
    private ResourceInterface $resource;
    private QueryRepository $queryRepository;
    private CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter;
    private RepositoryLoggerInterface $logger;
    private DonutRenderer $renderer;

    public function __construct(
        QueryRepository $queryRepository,
        HeaderSetter $headerSetter,
        ResourceStorageInterface $resourceStorage,
        ResourceInterface $resource,
        CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter,
        RepositoryLoggerInterface $logger,
        DonutRenderer $renderer
    ) {
        $this->resourceStorage = $resourceStorage;
        $this->headerSetter = $headerSetter;
        $this->resource = $resource;
        $this->queryRepository = $queryRepository;
        $this->cdnCacheControlHeaderSetter = $cdnCacheControlHeaderSetter;
        $this->logger = $logger;
        $this->renderer = $renderer;
    }

    public function get(ResourceObject $ro): ?ResourceObject
    {
        $maybeState = $this->queryRepository->get($ro->uri);
        $this->logger->log('try-donut-view: uri:%s', $ro->uri);
        if ($maybeState instanceof ResourceState) {
            $this->logger->log('found-donut-view: uri:%s', $ro->uri);
            $ro->headers = $maybeState->headers;
            $ro->view = $maybeState->view;

            return $ro;
        }

        return $this->refreshDonut($ro);
    }

    /**
     * {@inheritDoc}
     */
    public function putStatic(ResourceObject $ro, ?int $ttl = null, ?int $sMaxAge = null): ResourceObject
    {
        $this->logger->log('put-donut: uri:%s ttl:%s s-maxage:%d', (string) $ro->uri, $sMaxAge, $ttl);
        $keys = new SurrogateKeys($ro->uri);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $sMaxAge, true);
        $donut->render($ro, $this->renderer);
        $this->setHeaders($keys, $ro, $sMaxAge);
        // delete
        $this->resourceStorage->invalidateTags([(new UriTag())($ro->uri)]);
        // save content cache and donut
        $this->saveView($ro, $sMaxAge);
        $this->resourceStorage->saveDonut($ro->uri, $donut, $ttl);

        return $ro;
    }

    /**
     * {@inheritDoc}
     */
    public function putDonut(ResourceObject $ro, ?int $donutTtl): ResourceObject
    {
        $this->logger->log('put-donut: uri:%s ttl:%s', (string) $ro->uri, $donutTtl);
        $keys = new SurrogateKeys($ro->uri);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $donutTtl, false);
        $donut->render($ro, $this->renderer);
        $keys->setSurrogateHeader($ro);
        // delete
        $this->resourceStorage->invalidateTags([(new UriTag())($ro->uri)]);
        // save donut
        $this->resourceStorage->saveDonut($ro->uri, $donut, $donutTtl);

        return $ro;
    }

    /**
     * {@inheritDoc}
     */
    public function purge(AbstractUri $uri): void
    {
        $this->queryRepository->purge($uri);
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateTags(array $tags): void
    {
        $this->resourceStorage->invalidateTags($tags);
    }

    private function refreshDonut(ResourceObject $ro): ?ResourceObject
    {
        $donut = $this->resourceStorage->getDonut($ro->uri);
        $this->logger->log('try-donut uri:%s', (string) $ro->uri);
        if (! $donut instanceof ResourceDonut) {
            $this->logger->log('no-donut-found uri:%s', (string) $ro->uri);

            return null;
        }

        $this->logger->log('refresh-donut: uri:%s', $ro->uri);
        $donut->refresh($this->resource, $ro);
        if (! $donut->isCacheble) {
            return $ro;
        }

        ($this->headerSetter)($ro, $donut->ttl, null);
        $ro->headers[Header::ETAG] .= 'r'; // mark refreshed by resource static
        ($this->cdnCacheControlHeaderSetter)($ro, $donut->ttl);
        $this->saveView($ro, $donut->ttl);

        return $ro;
    }

    private function saveView(ResourceObject $ro, ?int $ttl): bool
    {
        assert(isset($ro->headers[Header::ETAG]));
        $surrogateKeys = $ro->headers[Header::SURROGATE_KEY] ?? '';
        $this->resourceStorage->saveEtag($ro->uri, $ro->headers[Header::ETAG], $surrogateKeys, $ttl);

        return $this->resourceStorage->saveDonutView($ro, $ttl);
    }

    private function setHeaders(SurrogateKeys $keys, ResourceObject $ro, ?int $sMaxAge): void
    {
        $keys->setSurrogateHeader($ro);
        ($this->cdnCacheControlHeaderSetter)($ro, $sMaxAge);
        ($this->headerSetter)($ro, 0, null);
    }
}
