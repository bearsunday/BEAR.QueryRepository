<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\AkamaiCacheControlHeaderSetter;
use BEAR\QueryRepository\Cdn\FastlyCacheControlHeaderSetter;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\CdnHeadersContext;
use BEAR\QueryRepository\Log\Context\ManualStoreContext;
use BEAR\QueryRepository\Log\Context\ManualStoreResultContext;
use BEAR\QueryRepository\Log\Context\PreWriteCleanupContext;
use BEAR\QueryRepository\Log\Context\PutDonutContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\QueryRepository\Log\Context\RefreshDonutContext;
use BEAR\QueryRepository\Log\Context\SaveDonutContext;
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function assert;
use function explode;
use function max;

final readonly class DonutRepository implements DonutRepositoryInterface
{
    public function __construct(
        private QueryRepositoryInterface $queryRepository,
        private HeaderSetter $headerSetter,
        private ResourceStorageInterface $resourceStorage,
        private ResourceInterface $resource,
        private CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private DonutRendererInterface $renderer,
        private UriTagInterface $uriTag = new UriTag(),
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
        $ttl = self::lifetime($ttl);
        $sMaxAge = self::lifetime($sMaxAge);

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
        $donutTtl = self::lifetime($donutTtl);

        return $this->store($ro, function () use ($ro, $donutTtl): void {
            $this->doPutDonut($ro, $donutTtl);
        });
    }

    /**
     * Clamp a requested lifetime the way the storage does
     *
     * `ResourceStorageSaver` calls expiresAfter() only for a positive value, so anything at
     * or below zero is stored with no expiry at all - which is what the schemas mean by
     * `0`: "no expiry is set, the entry lives until event-driven invalidation". Clamping
     * here keeps the recorded request equal to that effect instead of reporting a negative
     * lifetime the entry never had (`minimum: 0` in put_donut and every save_* schema).
     */
    private static function lifetime(int|null $seconds): int|null
    {
        return $seconds === null ? null : max(0, $seconds);
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
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $sMaxAge, true)->withStorageState($ttl, $headerKeys);
        $donut->render($ro, $this->renderer);
        $this->setHeaders($keys, $ro, $sMaxAge);
        $this->logCdnHeaders($ro);
        // delete: cleanup for the rewrite below, recorded as such at the source
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([($this->uriTag)($ro->uri)]);
        // save content cache and donut; the donut records the content state so that a
        // later refresh can keep Last-Modified when the recomposed content is identical
        $this->saveView($ro, $sMaxAge);
        $this->resourceStorage->saveDonut($ro->uri, $donut->withContentState($ro), $ttl, $headerKeys);
    }

    private function doPutDonut(ResourceObject $ro, int|null $donutTtl): void
    {
        $this->logger->event(new PutDonutContext((string) $ro->uri, $donutTtl, null));
        $keys = new SurrogateKeys($ro->uri);
        $keyArrays = $this->getHeaderKeys($ro);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $donutTtl, false)->withStorageState($donutTtl, $keyArrays);
        $donut->render($ro, $this->renderer);
        $keys->setSurrogateHeader($ro);
        $this->logCdnHeaders($ro);
        // delete: cleanup for the rewrite below, recorded as such at the source
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([($this->uriTag)($ro->uri)]);
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
            $this->logCdnHeaders($ro);
            $this->logger->event(new PutSkippedContext((string) $ro->uri, 'not-cacheable'));

            return $ro;
        }

        // When the recomposed content is byte-identical to the recorded one, carry over
        // the original Last-Modified instead of advancing it to the recomposition time
        $lastModified = $donut->getUnchangedLastModified((string) $ro->view);
        ($this->headerSetter)($ro, $donut->ttl, null, $lastModified);
        ($this->cdnCacheControlHeaderSetter)($ro, $donut->ttl);
        $this->logCdnHeaders($ro);
        if ($lastModified === null) {
            $this->recordContentState($ro, $donut);
        }

        $this->saveView($ro, $donut->ttl);

        return $ro;
    }

    /**
     * The CDN-facing headers this package's setters manage, lifetime and purge keys
     *
     * A custom CdnCacheControlHeaderSetterInterface using its own header name is outside
     * this list and therefore outside the log's knowledge.
     */
    private const CDN_FACING_HEADERS = [
        Header::CDN_CACHE_CONTROL,
        FastlyCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        AkamaiCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        ...self::CDN_KEY_HEADERS,
    ];

    /** The headers a CDN indexes responses under for key-based purging */
    private const CDN_KEY_HEADERS = [
        Header::SURROGATE_KEY,
        AkamaiCacheControlHeaderSetter::PURGE_KEYS,
    ];

    /**
     * Record the CDN-facing headers the response ended up with
     *
     * Read back from the response after the setters ran, so the log carries the effect:
     * the bound setter's default when no sMaxAge was requested, the resource's own value
     * when it set one, and no lifetime header when nothing gives the CDN one (the
     * un-cacheable donut page, or the Null setter). A state-served hit replays these
     * stored headers, so only writes and refreshes emit the event.
     */
    private function logCdnHeaders(ResourceObject $ro): void
    {
        $headers = [];
        foreach (self::CDN_FACING_HEADERS as $name) {
            if (isset($ro->headers[$name])) {
                $headers[$name] = $ro->headers[$name];
            }
        }

        $surrogateKeys = [];
        foreach (self::CDN_KEY_HEADERS as $name) {
            if (isset($ro->headers[$name])) {
                $surrogateKeys = [...$surrogateKeys, ...explode(' ', $ro->headers[$name])];
            }
        }

        $this->logger->event(new CdnHeadersContext((string) $ro->uri, $headers, $surrogateKeys));
    }

    /**
     * Record the recomposed content as the state the next refresh compares against
     *
     * The donut keeps the lifetime its template entry was stored with, so the state
     * rides along on the template's remaining time instead of restarting it. A
     * remaining time of 0 means that entry is expiring right now (the pool answered
     * within the second it lapses), and re-saving would hand it a fresh lifetime it
     * no longer has — so the skipped write is recorded rather than performed.
     */
    private function recordContentState(ResourceObject $ro, ResourceDonut $donut): void
    {
        $remainingTtl = $donut->getRemainingStorageTtl();
        $storageTags = $donut->getStorageTags();
        if ($remainingTtl === 0) {
            $this->logger->event(new SaveDonutContext((string) $ro->uri, $storageTags, 0, false));

            return;
        }

        $this->resourceStorage->saveDonut($ro->uri, $donut->withContentState($ro), $remainingTtl, $storageTags);
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
