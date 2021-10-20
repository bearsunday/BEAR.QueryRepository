<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function assert;

final class DonutRepository implements DonutRepositoryInterface
{
    /** @var ResourceStorageInterface */
    private $resourceStorage;

    /** @var HeaderSetter */
    private $headerSetter;

    /** @var ResourceInterface */
    private $resource;

    /** @var QueryRepository */
    private $queryRepository;

    /** @var CdnCacheControlHeaderSetterInterface */
    private $cacheControlHeaderSetter;

    /** @var RepositoryLoggerInterface */
    private $logger;

    public function __construct(
        QueryRepository $queryRepository,
        HeaderSetter $headerSetter,
        ResourceStorageInterface $resourceStorage,
        ResourceInterface $resource,
        CdnCacheControlHeaderSetterInterface $cacheControlHeaderSetter,
        RepositoryLoggerInterface $logger
    ) {
        $this->resourceStorage = $resourceStorage;
        $this->headerSetter = $headerSetter;
        $this->resource = $resource;
        $this->queryRepository = $queryRepository;
        $this->cacheControlHeaderSetter = $cacheControlHeaderSetter;
        $this->logger = $logger;
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
    public function put(ResourceObject $ro, ?int $ttl = null, ?int $sMaxAge = null): ResourceObject
    {
        $this->logger->log('create-donut: uri:%s ttl:%s s-maxage:%d', (string) $ro->uri, $sMaxAge, $ttl);
        $renderer = new DonutRenderer();
        $etags = new SurrogateKeys($ro->uri);
        $donut = ResourceDonut::create($ro, $renderer, $etags, $sMaxAge);

        $donut->render($ro, $renderer);
        $etags->setSurrogateHeader($ro);
        ($this->cacheControlHeaderSetter)($ro, $sMaxAge);
        ($this->headerSetter)($ro, 0, null);
        ($this->cacheControlHeaderSetter)($ro, $donut->ttl);
        $this->resourceStorage->invalidateTags([(new UriTag())($ro->uri)]);
        $this->saveView($ro, $sMaxAge);
        $this->resourceStorage->saveDonut($ro->uri, $donut, $ttl);

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
        ($this->headerSetter)($ro, $donut->ttl, null);
        $ro->headers[Header::ETAG] .= 'r'; // mark refreshed by resource static
        ($this->cacheControlHeaderSetter)($ro, $donut->ttl);
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
}
