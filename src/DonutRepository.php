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
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function array_unique;
use function array_values;
use function explode;
use function max;

final readonly class DonutRepository implements DonutRepositoryInterface
{
    private CacheDependencyInterface $cacheDependency;

    /**
     * A new dependency is always appended, never inserted before an existing parameter: a
     * direct (non-DI) caller's positional arguments must keep working across a minor
     * version bump.
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        private QueryRepositoryInterface $queryRepository,
        private DonutHeaderSetter $donutHeaderSetter,
        private ResourceStorageInterface $resourceStorage,
        private ResourceInterface $resource,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private DonutRendererInterface $renderer,
        private UnstorableGateInterface $gate,
        private UncacheableMarkInterface $mark,
        private UriTagInterface $uriTag = new UriTag(),
        CacheDependencyInterface|null $cacheDependency = null,
    ) {
        // Built from the same $uriTag/$logger this repository uses, not a bare default:
        // a caller with a custom UriTag must see materials tagged with that same tag.
        $this->cacheDependency = $cacheDependency ?? new CacheDependency($this->uriTag, $this->logger);
    }

    #[Override]
    public function get(ResourceObject $ro): ResourceObject|null
    {
        $maybeState = $this->queryRepository->get($ro->uri);
        if ($maybeState instanceof ResourceState) {
            // Field by field rather than ResourceState::visit(): a donut view is stored with an
            // empty body (ResourceStorage::saveDonutView()) and an unserialized uri, so visit()
            // would replace a live null body with [] and swap the request's uri object.
            $ro->code = $maybeState->code;
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
        $this->classifyMaterials($ro);
        $this->logger->event(new PutDonutContext((string) $ro->uri, $ttl, $sMaxAge));
        $keys = new SurrogateKeys($ro->uri);
        $keys->addTag($ro);
        $declaredKeys = $this->getHeaderKeys($ro);
        $templateKeys = $this->templateKeys($ro, $declaredKeys);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $sMaxAge, true, $this->mark)->withStorageState($ttl, $templateKeys);
        $donut->render($ro, $this->renderer);
        // Checked against the still-unmutated header, before setHeaders() below runs
        // headerSetter/setCdnCacheControl - the same point QueryRepository checks at (issue
        // #222). "any mark" semantics: the full content, holes included, is what gets stored.
        $unstorable = $this->gate->reason($ro);
        $surrogateKeys = $this->setHeaders($keys, $ro, $sMaxAge);
        $this->logCdnHeaders($ro);
        // delete: cleanup for the rewrite below, recorded as such at the source
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([($this->uriTag)($ro->uri)]);
        if ($unstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable));

            return;
        }

        // save content cache and donut; the donut records the content state so that a
        // later refresh can keep Last-Modified when the recomposed content is identical
        $this->saveView($ro, $sMaxAge, $surrogateKeys);
        $this->resourceStorage->saveDonut($ro->uri, $donut->withContentState($ro), $ttl, $templateKeys);
    }

    /**
     * A #[DonutCache] template holds placeholders for an embedded hole's content, but a page
     * can also write a per-user value straight into its own body/view, outside any hole - that
     * lands in the stored template verbatim and is served to every later visitor (issue #222),
     * so this write is gated.
     *
     * The gate is read after create()/render(), the same point doPutStatic reads it, because
     * create() is what actually runs each embedded hole's own onGet() (DonutRequest wraps the
     * embedded request precisely so that run happens there) - and the page's own template may
     * ALSO mark during that same (string) $ro call (a Twig helper producing a CSRF token
     * in-place, say), which a read taken before create() would miss entirely.
     *
     * Two different checks, because the template and the live response are not the same
     * content: the template write uses reasonExcludingHoleMarks() - never blocked by a
     * descendant hole's own mark, whose own storage is separately gated, keeping a page whose
     * holes "contain a form" working - while the live response (headers only; this path never
     * stores the response itself) uses reason() - any mark anywhere, page or any hole, because
     * every hole's rendered output is substituted into this response's own view even when the
     * template it is not baked into is unaffected.
     */
    private function doPutDonut(ResourceObject $ro, int|null $donutTtl): void
    {
        $this->classifyMaterials($ro);
        $this->logger->event(new PutDonutContext((string) $ro->uri, $donutTtl, null));
        $keys = new SurrogateKeys($ro->uri);
        $declaredKeys = $this->getHeaderKeys($ro);
        $templateKeys = $this->templateKeys($ro, $declaredKeys);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $donutTtl, false, $this->mark)->withStorageState($donutTtl, $templateKeys);
        $donut->render($ro, $this->renderer);
        $templateUnstorable = $this->gate->reasonExcludingHoleMarks($ro);
        $responseUnstorable = $this->gate->reason($ro);
        $keys->setSurrogateHeader($ro);
        if ($responseUnstorable !== null) {
            ($this->donutHeaderSetter)($ro, null, null);
        }

        $this->logCdnHeaders($ro);
        // delete: cleanup for the rewrite below, recorded as such at the source
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([($this->uriTag)($ro->uri)]);
        if ($templateUnstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $templateUnstorable));

            return;
        }

        // save donut
        $this->resourceStorage->saveDonut($ro->uri, $donut, $donutTtl, $templateKeys);
    }

    /**
     * Register body children the page's onGet() already ran as dependencies, before the template keys read the header
     *
     * A child carrying an ETag or Surrogate-Key already ran during onGet() (a material); a hole has not run yet.
     * Headers only: running a child here would execute a hole outside its hole scope (#222/#234).
     */
    private function classifyMaterials(ResourceObject $ro): void
    {
        /** @var mixed $body */
        foreach ((array) $ro->body as $body) {
            if (! ($body instanceof AbstractRequest)) {
                continue;
            }

            $child = $body->resourceObject;
            if (! isset($child->headers[Header::ETAG]) && ! isset($child->headers[Header::SURROGATE_KEY])) {
                continue;
            }

            $this->cacheDependency->depends($ro, $child);
        }
    }

    /**
     * The template's own URI tag (so `purge($uri)` reaches it, #185) and the declared keys, which include material
     * children's tags (classifyMaterials()). Holes are left out: their write drops only the content and the shell is recomposed.
     *
     * @param list<string> $declaredKeys
     *
     * @return list<string>
     */
    private function templateKeys(ResourceObject $ro, array $declaredKeys): array
    {
        return array_values(array_unique([($this->uriTag)($ro->uri), ...$declaredKeys]));
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
        $donut->refresh($this->resource, $ro, $this->mark);
        $surrogateKeys = $ro->headers[Header::SURROGATE_KEY] ?? '';

        // refresh() restores the stored headers before returning, discarding anything a
        // hole set on $ro (issue #222) - so a hole marking this response unstorable is only
        // visible on the mark itself, which is a separate per-request service and survives.
        // "any mark" semantics here too: the recomposed content, holes included, is what
        // the storable/unstorable decision below is about.
        $unstorable = $this->gate->reason($ro);

        if (! $donut->isCacheble) {
            // The donut was created by putDonut (isCacheble=false): only the template is
            // cached and the page is never stored as a rendered view, so there is no
            // page-level entry to save after the refresh - and this package never hands
            // the CDN a lifetime header of its own for this kind, mirroring doPutDonut's
            // MISS-path exactly: only touch the restored headers (HeaderSetter overwrites
            // Cache-Control/Pragma when a mark fired, keeps a hand-set private/no-store
            // header as-is otherwise - issue #222) when a hole marks itself on THIS
            // recomposition, never add a lifetime header that was never there (a hole
            // marking itself must still be caught on a later, recomposed hit, not just the
            // one that first wrote the template; but a storable template-only hit must
            // stay exactly as storable as the MISS that created it, no new CDN lifetime
            // invented here).
            if ($unstorable !== null) {
                ($this->donutHeaderSetter)($ro, null, null);
            }

            $this->logCdnHeaders($ro);
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable ?? 'not-cacheable'));

            return $ro;
        }

        // When the recomposed content is byte-identical to the recorded one, carry over
        // the original Last-Modified instead of advancing it to the recomposition time
        $lastModified = $unstorable === null ? $donut->getUnchangedLastModified((string) $ro->view) : null;
        // Always run, unstorable or not: this is what strips the restored, stale-but-
        // "storable" CDN headers from an unstorable response and - when a mark fired -
        // also replaces Cache-Control/Pragma (HeaderSetter, issue #222).
        ($this->donutHeaderSetter)($ro, $donut->ttl, null, $lastModified);
        $this->donutHeaderSetter->setCdnCacheControl($ro, $donut->ttl);
        $this->logCdnHeaders($ro);
        if ($unstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable));

            return $ro;
        }

        if ($lastModified === null) {
            $this->recordContentState($ro, $donut);
        }

        $this->saveView($ro, $donut->ttl, $surrogateKeys);

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
        // Templates stored before the template carried its own URI tag have an empty tag list;
        // re-tagging here makes an old entry reachable without a cache flush.
        $storageTags = $this->templateKeys($ro, $donut->getStorageTags());
        if ($remainingTtl === 0) {
            $this->logger->event(new SaveDonutContext((string) $ro->uri, $storageTags, 0, false));

            return;
        }

        $this->resourceStorage->saveDonut($ro->uri, $donut->withContentState($ro), $remainingTtl, $storageTags);
    }

    /**
     * Save the page state, and its validator when it has one
     *
     * A validator is issued for 200 only (EtagSetter), while the donut save gate lets any
     * status below 400 through, so a stored 3xx or 204 arrives here with no ETag. The entry
     * is keyed and invalidated by its URI tag, never by ETag (ResourceStorage::getTags()),
     * so the save does not need one. Same shape as QueryRepository::doPut().
     *
     * The surrogate keys arrive as a parameter because the CDN setter may have renamed the
     * header by now: Akamai's moves Surrogate-Key to Edge-Cache-Tag.
     */
    private function saveView(ResourceObject $ro, int|null $ttl, string $surrogateKeys): bool
    {
        if (isset($ro->headers[Header::ETAG])) {
            $this->resourceStorage->saveEtag($ro->uri, $ro->headers[Header::ETAG], $surrogateKeys, $ttl);
        }

        $tags = $surrogateKeys === '' ? [] : explode(' ', $surrogateKeys);

        return $this->resourceStorage->saveDonutView($ro, $ttl, $tags);
    }

    /** @return string The Surrogate-Key header as composed, before the CDN setter runs */
    private function setHeaders(SurrogateKeys $keys, ResourceObject $ro, int|null $sMaxAge): string
    {
        $keys->setSurrogateHeader($ro);
        $surrogateKeys = $ro->headers[Header::SURROGATE_KEY] ?? '';
        $this->donutHeaderSetter->setCdnCacheControl($ro, $sMaxAge);
        ($this->donutHeaderSetter)($ro, 0, null);

        return $surrogateKeys;
    }

    /** @return list<string> */
    public function getHeaderKeys(ResourceObject $ro): array
    {
        return isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
    }
}
