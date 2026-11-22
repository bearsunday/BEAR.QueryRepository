<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

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
use Throwable;

use function explode;
use function max;

/** @internal */
final readonly class DonutRepository implements DonutRepositoryInterface
{
    /**
     * A page stored without a lifetime lives until a write invalidates it, so a browser revalidates
     * it every time instead of reusing it for a lifetime guessed from Last-Modified
     */
    private const REVALIDATE = 'max-age=0';

    /** @SuppressWarnings("PHPMD.ExcessiveParameterList") */
    public function __construct(
        private QueryRepositoryInterface $queryRepository,
        private HeaderSetter $headerSetter,
        private ResourceStorageInterface $resourceStorage,
        private ResourceInterface $resource,
        private CdnCacheControlHeaderSetterInterface $cdnCacheControlHeaderSetter,
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private DonutRendererInterface $renderer,
        private UriTagInterface $uriTag,
        private DeclaredCacheControl $declaredCacheControl,
        private CacheTags $cacheTags,
        private CacheDependencyCollector $dependencyCollector,
    ) {
    }

    #[Override]
    public function get(ResourceObject $ro): ResourceObject|null
    {
        // A read that fails hands the resource back as it came in, so the page can run on it as on a miss
        $incoming = [$ro->body, $ro->headers, $ro->code, $ro->view];
        try {
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
        } catch (Throwable $e) {
            [$ro->body, $ro->headers, $ro->code, $ro->view] = $incoming;

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function putStatic(ResourceObject $ro, int|null $ttl = null, int|null $sMaxAge = null): ResourceObject
    {
        $ttl = $this->lifetime($ttl);
        $sMaxAge = $this->lifetime($sMaxAge);

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
        $donutTtl = $this->lifetime($donutTtl);

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
    private function lifetime(int|null $seconds): int|null
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
        $keys = $this->dependencyCollector->begin($this->cacheTags->own($ro));
        $this->dependencyCollector->materials($ro, $keys);
        $this->logger->event(new PutDonutContext((string) $ro->uri, $ttl, $sMaxAge));
        $templateKeys = $this->templateKeys($ro);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $sMaxAge, true)->withStorageState($ttl, $templateKeys->toArray());
        $donut->render($ro, $this->renderer);
        // Checked on the rendered response before setHeaders() mutates it
        $unstorable = $this->declaredCacheControl->reason($ro);
        $surrogateKeys = $this->setHeaders($keys, $ro, $sMaxAge, $unstorable);
        $this->logCdnHeaders($ro);
        $this->dropReplacedEntry($ro);
        if ($unstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable));

            return;
        }

        // The donut's content state lets a later refresh keep Last-Modified when the
        // recomposed content is unchanged.
        $this->saveView($ro, $sMaxAge, $surrogateKeys);
        $this->resourceStorage->saveDonut($ro->uri, $donut->withContentState($ro), $ttl, $templateKeys->toArray());
    }

    private function doPutDonut(ResourceObject $ro, int|null $donutTtl): void
    {
        $keys = $this->dependencyCollector->begin($this->cacheTags->own($ro));
        $this->dependencyCollector->materials($ro, $keys);
        $this->logger->event(new PutDonutContext((string) $ro->uri, $donutTtl, null));
        $templateKeys = $this->templateKeys($ro);
        $donut = ResourceDonut::create($ro, $this->renderer, $keys, $donutTtl, false)->withStorageState($donutTtl, $templateKeys->toArray());
        $donut->render($ro, $this->renderer);
        $unstorable = $this->declaredCacheControl->reason($ro);
        $keys->addTo($ro);
        $this->cacheTags->tagHeader($ro);
        if ($unstorable !== null) {
            $this->headerSetter->strip($ro);
        }

        $this->logCdnHeaders($ro);
        $this->dropReplacedEntry($ro);
        if ($unstorable !== null) {
            $this->logger->event(new PutSkippedContext((string) $ro->uri, $unstorable));

            return;
        }

        $this->resourceStorage->saveDonut($ro->uri, $donut, $donutTtl, $templateKeys->toArray());
    }

    /** {@see QueryRepository::dropReplacedEntry()} */
    private function dropReplacedEntry(ResourceObject $ro): void
    {
        if ($this->resourceStorage->get($ro->uri) === null && $this->resourceStorage->getDonut($ro->uri) === null) {
            return;
        }

        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->resourceStorage->invalidateTags([($this->uriTag)($ro->uri)]);
    }

    /**
     * The template's own URI tag (so `purge($uri)` reaches it, #185) and the declared keys: material children's tags
     * ({@see CacheDependencyCollector::materials()}). Holes are left out: their write drops only the content and the shell is recomposed.
     */
    private function templateKeys(ResourceObject $ro): TagSet
    {
        return $this->cacheTags->tags($ro);
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
        $donut->refresh($this->resource, $ro, $this->dependencyCollector->begin(new TagSet([($this->uriTag)($ro->uri)])));
        $surrogateKeys = TagSet::ofHeaders($ro->headers);

        if (! $donut->isCacheble) {
            // Template-only (isCacheble=false): never stored as a page view.
            $this->logCdnHeaders($ro);
            $this->logger->event(new PutSkippedContext((string) $ro->uri, 'not-cacheable'));

            return $ro;
        }

        // When the recomposed content is byte-identical to the recorded one, carry over
        // the original Last-Modified instead of advancing it to the recomposition time
        $lastModified = $donut->getUnchangedLastModified((string) $ro->view);
        $unstorable = $this->declaredCacheControl->reason($ro);
        ($this->headerSetter)($ro, $unstorable, $this->headerSetter->maxAge($donut->ttl) ?? self::REVALIDATE, null, $lastModified);

        $this->setCdnCacheControl($ro, $donut->ttl, $unstorable);
        $this->logCdnHeaders($ro);
        if ($lastModified === null) {
            $this->recordContentState($ro, $donut);
        }

        $this->saveView($ro, $donut->ttl, $surrogateKeys);

        return $ro;
    }

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
        foreach (HeaderSetter::CDN_FACING_HEADERS as $name) {
            if (isset($ro->headers[$name])) {
                $headers[$name] = $ro->headers[$name];
            }
        }

        $surrogateKeys = [];
        foreach (HeaderSetter::CDN_KEY_HEADERS as $name) {
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
        $storageTags = $donut->getStorageTags();
        if ($remainingTtl === 0) {
            $this->logger->event(new SaveDonutContext((string) $ro->uri, $storageTags, 0, false));

            return;
        }

        $this->resourceStorage->saveDonut($ro->uri, $donut->withContentState($ro), $remainingTtl, $storageTags);
    }

    /**
     * Save the page state, and its validator when it has one and the state is stored
     *
     * A 3xx/204 arrives here with no ETag (only 200 gets one), but the entry is keyed and
     * invalidated by its URI tag, never by ETag, so that's fine. Surrogate keys arrive as a
     * parameter because the CDN setter may have already renamed the header (Akamai moves
     * Surrogate-Key to Edge-Cache-Tag).
     */
    private function saveView(ResourceObject $ro, int|null $ttl, TagSet $surrogateKeys): bool
    {
        return $this->resourceStorage->saveDonutView($ro, $ttl, $surrogateKeys->toArray());
    }

    /**
     * Set the page's own headers (validator, client revalidation, CDN lifetime)
     *
     * @return TagSet the Surrogate-Key header as composed, before the CDN setter runs
     */
    private function setHeaders(Dependencies $keys, ResourceObject $ro, int|null $sMaxAge, string|null $unstorable): TagSet
    {
        $keys->addTo($ro);
        $this->cacheTags->tagHeader($ro);
        $surrogateKeys = TagSet::ofHeaders($ro->headers);
        $this->setCdnCacheControl($ro, $sMaxAge, $unstorable);
        ($this->headerSetter)($ro, $unstorable, $this->headerSetter->maxAge($sMaxAge) === null ? self::REVALIDATE : null, null);

        return $surrogateKeys;
    }

    /**
     * Hand the CDN a lifetime for 200 that is also storable, and not declared private
     *
     * The setters do not read the status code and the Fastly and Akamai defaults are
     * 31_536_000 seconds - a year - so an unguarded call would tell every shared cache to
     * hold a 301 or a 204 for that long. A response whose Cache-Control already reads
     * private/no-store is refused the same way: the check sits here rather than in the
     * setters so it also covers an application's own CdnCacheControlHeaderSetterInterface.
     */
    private function setCdnCacheControl(ResourceObject $ro, int|null $sMaxAge, string|null $unstorable): void
    {
        if ($this->declaredCacheControl->isPrivate($ro)) {
            $this->headerSetter->stripLifetime($ro);

            return;
        }

        if ($ro->code !== 200 || $unstorable !== null) {
            return;
        }

        ($this->cdnCacheControlHeaderSetter)($ro, $sMaxAge);
    }
}
