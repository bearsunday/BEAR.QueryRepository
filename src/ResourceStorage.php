<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\InvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualInvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualInvalidateResultContext;
use BEAR\QueryRepository\Log\Context\SaveDonutContext;
use BEAR\QueryRepository\Log\Context\SaveDonutViewContext;
use BEAR\QueryRepository\Log\Context\SaveEtagContext;
use BEAR\QueryRepository\Log\Context\SaveValueContext;
use BEAR\QueryRepository\Log\Context\SaveViewContext;
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\Di\Set;
use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Throwable;

use function array_merge;
use function array_unique;
use function array_values;
use function assert;
use function explode;
use function hrtime;
use function implode;
use function max;
use function round;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * @psalm-type Props = array{
 *     logger: SemanticLoggerInterface,
 *     purger:PurgerInterface,
 *     uriTag: UriTag,
 *     saver: ResourceStorageSaver,
 *     roProvider:ProviderInterface<TagAwareAdapterInterface>,
 *     etagProvider: ProviderInterface<TagAwareAdapterInterface>,
 *     serverContext: ServerContextInterface
 * }
 */
final class ResourceStorage implements ResourceStorageInterface, ScopedValidatorInterface
{
    /**
     * Resource object cache prefix
     */
    private const KEY_RO = 'ro-';

    /**
     * Resource static cache prifix
     */
    private const KEY_DONUT = 'donut-';

    /**
     * CDN status when the purge did not throw, indexed by (int) no-CDN:
     * [0] a configured purger ran -> "purged", [1] NullPurger -> "skipped"
     */
    private const CDN_OK_STATUS = ['purged', 'skipped'];

    /** @var ProviderInterface<TagAwareAdapterInterface> */
    private ProviderInterface $roPoolProvider;

    /** @var ProviderInterface<TagAwareAdapterInterface> */
    private ProviderInterface $etagPoolProvider;
    private TagAwareAdapterInterface $roPool;
    private TagAwareAdapterInterface $etagPool;

    /**
     * @param ProviderInterface<TagAwareAdapterInterface> $roPoolProvider
     * @param ProviderInterface<TagAwareAdapterInterface> $etagPoolProvider
     */
    public function __construct(
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private PurgerInterface $purger,
        private UriTagInterface $uriTag,
        private ResourceStorageSaver $saver,
        private ServerContextInterface $serverContext,
        #[Set(TagAwareAdapterInterface::class, ResourceObjectPool::class)]
        ProviderInterface $roPoolProvider,
        #[Set(TagAwareAdapterInterface::class, EtagPool::class)]
        ProviderInterface $etagPoolProvider,
        private ResourceBodyEvaluator $evaluateBody = new ResourceBodyEvaluator(),
    ) {
        $this->initializePools($roPoolProvider, $etagPoolProvider);
    }

    /**
     * @param ProviderInterface<TagAwareAdapterInterface> $roPoolProvider
     * @param ProviderInterface<TagAwareAdapterInterface> $etagPoolProvider
     */
    private function initializePools(ProviderInterface $roPoolProvider, ProviderInterface $etagPoolProvider): void
    {
        $this->roPoolProvider = $roPoolProvider;
        $this->etagPoolProvider = $etagPoolProvider;
        $this->roPool = $roPoolProvider->get();
        $etagPool = $this->etagPoolProvider->get();
        $this->etagPool = $etagPool instanceof TagAwareAdapterInterface ? $etagPool : $this->roPool; // @phpstan-ignore-line
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        $item = $this->roPool->getItem($this->getUriKey($uri, self::KEY_RO));
        $state = $item->get();
        assert($state instanceof ResourceState || $state === null);

        return $state;
    }

    #[Override]
    public function getDonut(AbstractUri $uri): ResourceDonut|null
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $item = $this->roPool->getItem($key);
        $donut = $item->get();
        assert($donut instanceof ResourceDonut || $donut === null);

        return $donut;
    }

    /**
     * {@inheritDoc}
     *
     * The stored value is the URI tag the validator was issued for. An entry written before that
     * was recorded holds the old `etag` placeholder and cannot be scoped, so it answers false: one
     * full response per client after an upgrade, once.
     */
    #[Override]
    public function hasEtagFor(string $etag, AbstractUri $uri): bool
    {
        return $this->findEtag($etag, ($this->uriTag)($uri));
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function hasEtag(string $etag): bool
    {
        return $this->findEtag($etag, null);
    }

    /** Is a live entry for this validator, issued for $uriTag when one is named? */
    private function findEtag(string $etag, string|null $uriTag): bool
    {
        foreach (EntityTags::of($etag) as $opaqueTag) {
            $item = $this->etagPool->getItem($opaqueTag);
            if ($item->isHit() && ($uriTag === null || $item->get() === $uriTag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function deleteEtag(AbstractUri $uri)
    {
        $uriTag = ($this->uriTag)($uri);

        return $this->invalidateTags([$uriTag]);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function invalidateTags(array $tags): bool
    {
        $start = hrtime(true);
        $roOk = $this->roPool->invalidateTags($tags);
        $etagOk = $this->etagPool->invalidateTags($tags);

        // The CDN purge is fail-closed: a purge failure must surface so a write does not
        // silently leave stale CDN content. The local pools are invalidated first, and the
        // outcome is logged (cdn=failed) before the exception is re-thrown to the caller.
        $purgerError = null;
        try {
            ($this->purger)(implode(' ', $tags));
        } catch (Throwable $e) {
            $purgerError = $e;
        }

        $result = new InvalidateContext(
            $tags,
            roPoolInvalidated: $roOk,
            etagPoolInvalidated: $etagOk,
            cdnStatus: $purgerError === null ? $this->getCdnOkStatus() : 'failed',
            durationMs: round((hrtime(true) - $start) / 1_000_000, 3),
        );

        $this->logInvalidation($result, $tags);

        if ($purgerError !== null) {
            throw $purgerError;
        }

        return $roOk && $etagOk;
    }

    /**
     * CDN status when the purge did not throw: "skipped" when no CDN is configured
     * (NullPurger), "purged" when a real purger ran — a branch-free lookup
     *
     * @return "purged"|"skipped"
     */
    private function getCdnOkStatus(): string
    {
        return self::CDN_OK_STATUS[(int) ($this->purger instanceof NullPurger)];
    }

    /**
     * Record an invalidation outcome
     *
     * A top-level invalidation is a direct (non-AOP) call: root it in a manual_invalidate
     * scope whose close carries the outcome, so it stands out from an invalidation the
     * framework drove. Nested invalidations (inside a GET or a command) stay events.
     *
     * @param list<string> $tags
     */
    private function logInvalidation(InvalidateContext $result, array $tags): void
    {
        if ($this->logger instanceof TopLevelAwareInterface && $this->logger->isTopLevel()) {
            // The detail stays on the event so every invalidation - manual or framework-
            // driven - is findable among the events (the save_*/invalidate tag correlation
            // the reading rules teach); the close is the one-word verdict.
            $openId = $this->logger->open(new ManualInvalidateContext($tags));
            $this->logger->event($result);
            $invalidated = $result->roPoolInvalidated && $result->etagPoolInvalidated && $result->cdnStatus !== 'failed';
            $this->logger->close(new ManualInvalidateResultContext($invalidated), $openId);

            return;
        }

        $this->logger->event($result);
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    #[Override]
    public function saveValue(ResourceObject $ro, int $ttl)
    {
        $ttl = max(0, $ttl);
        /** @psalm-suppress MixedAssignment $body */
        $body = ($this->evaluateBody)($ro->body);
        $value = ResourceState::create($ro, $body, null);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $saved = $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
        $this->logger->event(new SaveValueContext((string) $ro->uri, $tags, $ttl, $saved));

        return $saved;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    #[Override]
    public function saveView(ResourceObject $ro, int $ttl)
    {
        $ttl = max(0, $ttl);
        /** @psalm-suppress MixedAssignment $body */
        $body = ($this->evaluateBody)($ro->body);
        $value = ResourceState::create($ro, $body, $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $saved = $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
        $this->logger->event(new SaveViewContext((string) $ro->uri, $tags, $ttl, $saved));

        return $saved;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
        // Despite the legacy parameter name (kept for BC), this argument carries the donut
        // template entry TTL (putStatic passes $ttl, putDonut passes $donutTtl), never a CDN s-maxage.
        $sMaxAge = $sMaxAge === null ? null : max(0, $sMaxAge);
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $saved = $this->saver->__invoke($key, $donut, $this->roPool, $headerKeys, $sMaxAge);
        // saved=false is logged, not asserted: a quiet store failure must stay observable
        // in the log (an assert here would throw AFTER the event, contradicting it).
        $this->logger->event(new SaveDonutContext((string) $uri, $headerKeys, $sMaxAge, $saved));
    }

    #[Override]
    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool
    {
        $ttl = $ttl === null ? null : max(0, $ttl);
        $resourceState = ResourceState::create($ro, [], $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $saved = $this->saver->__invoke($key, $resourceState, $this->roPool, $tags, $ttl);
        $this->logger->event(new SaveDonutViewContext((string) $ro->uri, $tags, $ttl, $saved));

        return $saved;
    }

    /** @return list<string> */
    private function getTags(ResourceObject $ro): array
    {
        // ETag is intentionally NOT used as an invalidation tag. The cache entry is
        // purged by its URI tag (deleteEtag/invalidateTags) and surrogate keys; no code
        // path ever invalidates by ETag. Because ETag is content-versioned, registering it
        // as a tag produced one non-volatile tag Set per content version that, under a
        // volatile-* eviction policy, is never reclaimed - leaking memory without being read.
        $tags = [($this->uriTag)($ro->uri)];
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $tags = array_merge($tags, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }

        /** @var list<string> $uniqueTags */
        $uniqueTags = array_values(array_unique($tags));

        return $uniqueTags;
    }

    private function getUriKey(AbstractUri $uri, string $type): string
    {
        return $type . ($this->uriTag)($uri) . ($this->serverContext->has('X_VARY') ? $this->getVary() : '');
    }

    private function getVary(): string
    {
        $xvary = $this->serverContext->get('X_VARY');
        assert($xvary !== null, 'getVary() is only called when X_VARY exists');

        $varys = explode(',', $xvary);
        $varyString = '';
        foreach ($varys as $vary) {
            $vary = trim($vary);
            if ($vary === '') {
                continue;
            }

            $phpVaryKey = sprintf('X_%s', strtoupper($vary));
            $value = $this->serverContext->get($phpVaryKey);
            if ($value !== null) {
                $varyString .= $value;
            }
        }

        return $varyString;
    }

    #[Override]
    public function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, int|null $ttl): void
    {
        $ttl = $ttl === null ? null : max(0, $ttl);
        $tags = $surrogateKeys !== '' ? explode(' ', $surrogateKeys) : [];
        $tags[] = ($this->uriTag)($uri);
        /** @var list<string> $uniqueTags */
        $uniqueTags = array_values(array_unique($tags));
        // The header value is a quoted entity-tag; the pool key is the bare opaque-tag. The entry's
        // value is the URI tag it was issued for - the field used to be the constant 'etag', which
        // is why a validator from any resource satisfied any request.
        $saved = $this->saver->__invoke(trim($etag, '"'), ($this->uriTag)($uri), $this->etagPool, $uniqueTags, $ttl);
        $this->logger->event(new SaveEtagContext((string) $uri, $etag, $uniqueTags, $ttl, $saved));
    }

    public function __serialize(): array
    {
        return [
            'logger' => $this->logger,
            'purger' => $this->purger,
            'uriTag' => $this->uriTag,
            'saver' => $this->saver,
            'roProvider' => $this->roPoolProvider,
            'etagProvider' => $this->etagPoolProvider,
            'serverContext' => $this->serverContext,
        ];
    }

    /**
     * @param Props $data
     *
     * @return void
     */
    public function __unserialize(array $data): void
    {
        $this->logger = $data['logger'];
        $this->purger = $data['purger'];
        $this->uriTag = $data['uriTag'];
        $this->saver = $data['saver'];
        $this->serverContext = $data['serverContext'];
        // Stateless, so rebuilt rather than carried: left out of the payload it stays
        // uninitialized, and the first save after a compiled-injector restore throws.
        $this->evaluateBody = new ResourceBodyEvaluator();
        $this->initializePools($data['roProvider'], $data['etagProvider']);
    }
}
