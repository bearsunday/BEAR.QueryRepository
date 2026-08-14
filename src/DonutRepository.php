<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ManualStoreContext;
use BEAR\QueryRepository\Log\Context\ManualStoreResultContext;
use BEAR\QueryRepository\Log\Context\PreWriteCleanupContext;
use BEAR\QueryRepository\Log\Context\PutDonutContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\QueryRepository\Log\Context\RefreshDonutContext;
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function assert;
use function explode;

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
        return $this->store($ro, function () use ($ro, $ttl, $sMaxAge): void {
            $this->doPutStatic($ro, $ttl, $sMaxAge);
        });
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function putDonut(ResourceObject $ro, int|null $donutTtl): ResourceObject
    {
        return $this->store($ro, function () use ($ro, $donutTtl): void {
            $this->doPutDonut($ro, $donutTtl);
        });
    }

    /**
     * Run a donut write, rooting it in a manual_store scope when it is top-level
     *
     * A top-level donut write is a direct (non-AOP) call on the repository, so it is
     * rooted like a direct put()/purge()/invalidateTags(): every event the write emits —
     * including the pre-write cleanup invalidation, which is no longer top-level inside
     * the scope and therefore no longer opens a manual_invalidate of its own — is nested
     * under one scope instead of scattering over the session root. A write nested in a
     * request GET or a write command keeps emitting its events under that scope.
     *
     * The close says `stored` when the write ran to completion: the storage saves the
     * donut through a void call, so the per-entry outcomes stay on the nested
     * save_donut / save_donut_view events and only an aborted write reads as `failed`.
     *
     * @param callable(): void $write
     */
    private function store(ResourceObject $ro, callable $write): ResourceObject
    {
        if (! $this->logger instanceof TopLevelAwareInterface || ! $this->logger->isTopLevel()) {
            $write();

            return $ro;
        }

        $openId = $this->logger->open(new ManualStoreContext((string) $ro->uri));
        $stored = false;
        try {
            $write();
            $stored = true;

            return $ro;
        } finally {
            $this->logger->close(new ManualStoreResultContext($stored), $openId);
        }
    }

    private function doPutStatic(ResourceObject $ro, int|null $ttl, int|null $sMaxAge): void
    {
        $this->logger->event(new PutDonutContext((string) $ro->uri, $ttl, $sMaxAge));
        $keys = new SurrogateKeys($ro->uri);
        $keys->addTag($ro);
        $headerKeys = $this->getHeaderKeys($ro);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $sMaxAge, true);
        $donut->render($ro, $this->renderer);
        $this->setHeaders($keys, $ro, $sMaxAge);
        // delete: cleanup for the rewrite below, recorded as such at the source
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([(new UriTag())($ro->uri)]);
        // save content cache and donut
        $this->saveView($ro, $sMaxAge);
        $this->resourceStorage->saveDonut($ro->uri, $donut, $ttl, $headerKeys);
    }

    private function doPutDonut(ResourceObject $ro, int|null $donutTtl): void
    {
        $this->logger->event(new PutDonutContext((string) $ro->uri, $donutTtl, null));
        $keys = new SurrogateKeys($ro->uri);
        $keyArrays = $this->getHeaderKeys($ro);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $donutTtl, false);
        $donut->render($ro, $this->renderer);
        $keys->setSurrogateHeader($ro);
        // delete: cleanup for the rewrite below, recorded as such at the source
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([(new UriTag())($ro->uri)]);
        // save donut
        $this->resourceStorage->saveDonut($ro->uri, $donut, $donutTtl, $keyArrays);
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
            // The donut was created by putDonut (isCacheble=false): only the template is
            // cached and the page is never stored as a rendered view, so there is no
            // page-level entry to save after the refresh. Record the skip — without it
            // the scope shows a refresh with no saves and no reason.
            $this->logger->event(new PutSkippedContext((string) $ro->uri, 'not-cacheable'));

            return $ro;
        }

        ($this->headerSetter)($ro, $donut->ttl, null);
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
