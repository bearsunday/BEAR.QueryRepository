<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function assert;
use function explode;

final class DonutRepository implements DonutRepositoryInterface
{
    public function __construct(
        private QueryRepositoryInterface $queryRepository,
        private HeaderSetter $headerSetter,
        private ResourceStorageInterface $resourceStorage,
        private ResourceInterface $resource,
        private CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter,
        private RepositoryLoggerInterface $logger,
        private DonutRendererInterface $renderer,
    ) {
    }

    public function get(ResourceObject $ro): ResourceObject|null
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
    public function putStatic(ResourceObject $ro, int|null $ttl = null, int|null $sMaxAge = null): ResourceObject
    {
        $this->logger->log('put-donut: uri:%s ttl:%s s-maxage:%d', (string) $ro->uri, $sMaxAge, $ttl);
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
    public function putDonut(ResourceObject $ro, int|null $donutTtl): ResourceObject
    {
        $this->logger->log('put-donut: uri:%s ttl:%s', (string) $ro->uri, $donutTtl);
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

    private function refreshDonut(ResourceObject $ro): ResourceObject|null
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
