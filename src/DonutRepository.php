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
            // Field by field, not visit(): a stored donut view's empty body and unserialized
            // uri would clobber the live null body and uri object.
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
     * `ResourceStorageSaver` treats zero or below as no expiry; clamping here keeps the
     * recorded lifetime at 0 instead of a negative value the entry never had.
     */
    private static function lifetime(int|null $seconds): int|null
    {
        return $seconds === null ? null : max(0, $seconds);
    }

    /**
     * Run a donut write, rooting it in a manual_store scope only when top-level
     *
     * A direct (non-AOP) call roots its own scope so every event it emits nests under one
     * scope instead of scattering at the session root; a nested call emits into the
     * caller's scope instead. The close reports `stored` once the write (a void call)
     * completes.
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
        // Gate checked against the unmutated response, before setHeaders() mutates it
        // (#222): "any mark" anywhere in the content decides storability.
        $unstorable = $this->gate->reason($ro);
        $surrogateKeys = $this->setHeaders($keys, $ro, $sMaxAge);
        $this->logCdnHeaders($ro);
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([($this->uriTag)($ro->uri)]);
        if ($unstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable));

            return;
        }

        // The donut's content state lets a later refresh keep Last-Modified when the
        // recomposed content is unchanged.
        $this->saveView($ro, $sMaxAge, $surrogateKeys);
        $this->resourceStorage->saveDonut($ro->uri, $donut->withContentState($ro), $ttl, $templateKeys);
    }

    /**
     * Write the donut, gated against a per-user value baked into the template outside a hole (#222)
     *
     * The gate is read after create()/render(), since create() runs each hole's own onGet()
     * and may itself mark. The template write excludes hole marks
     * (reasonExcludingHoleMarks()); the live response does not, since every hole's output
     * lands in it too.
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
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([($this->uriTag)($ro->uri)]);
        if ($templateUnstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $templateUnstorable));

            return;
        }

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

        // refresh() discards any header a hole set on $ro (#222); an unstorable mark
        // survives only via the separate per-request mark service, read below instead.
        $unstorable = $this->gate->reason($ro);

        if (! $donut->isCacheble) {
            // Template-only (isCacheble=false): never stored as a page view, so only refresh
            // restored headers here (#222) - never invent a new CDN lifetime for this hit.
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
        // Always run, unstorable or not: strips restored-but-stale "storable" CDN headers from
        // an unstorable response and, when a mark fired, also replaces Cache-Control/Pragma (#222).
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
     * Read back after the setters ran, so the log reflects the actual effect, not the
     * request. A state-served hit replays these stored headers, so only writes and
     * refreshes emit this event.
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
     * The state rides on the template's remaining TTL, not a fresh one. When that
     * remaining time is 0, saving would hand it a lifetime it no longer has, so the write
     * is skipped and recorded instead.
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
     * A 3xx/204 arrives here with no ETag (only 200 gets one), but the entry is keyed and
     * invalidated by its URI tag, never by ETag, so that's fine. Surrogate keys arrive as a
     * parameter because the CDN setter may have already renamed the header (Akamai moves
     * Surrogate-Key to Edge-Cache-Tag).
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
