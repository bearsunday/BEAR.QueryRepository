<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\QueryRepository\Log\Context\InvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualInvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualInvalidateResultContext;
use BEAR\QueryRepository\Log\Context\SaveDonutContext;
use BEAR\QueryRepository\Log\Context\SaveDonutViewContext;
use BEAR\QueryRepository\Log\Context\SaveValueContext;
use BEAR\QueryRepository\Log\Context\SaveViewContext;
use BEAR\QueryRepository\Log\TopLevelAwareInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\Di\Set;
use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Throwable;

use function array_unique;
use function assert;
use function explode;
use function hrtime;
use function implode;
use function max;
use function round;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * @psalm-type Props = array{
 *     logger: SemanticLoggerInterface,
 *     purger:PurgerInterface,
 *     saver: ResourceStorageSaver,
 *     roProvider:ProviderInterface<TagAwareAdapterInterface>,
 *     identityFactory: CacheIdentityFactory,
 *     cacheTags: CacheTags,
 * }
 * @internal
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
    private TagAwareAdapterInterface $roPool;

    /** @param ProviderInterface<TagAwareAdapterInterface> $roPoolProvider */
    public function __construct(
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private PurgerInterface $purger,
        private ResourceStorageSaver $saver,
        private readonly CacheIdentityFactory $identityFactory,
        #[Set(TagAwareAdapterInterface::class, ResourceObjectPool::class)]
        ProviderInterface $roPoolProvider,
        private readonly CacheTags $cacheTags,
        private ResourceBodyEvaluator $evaluateBody = new ResourceBodyEvaluator(),
    ) {
        $this->initializePools($roPoolProvider);
    }

    /** @param ProviderInterface<TagAwareAdapterInterface> $roPoolProvider */
    private function initializePools(ProviderInterface $roPoolProvider): void
    {
        $this->roPoolProvider = $roPoolProvider;
        $this->roPool = $roPoolProvider->get();
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        $item = $this->guard(fn () => $this->roPool->getItem($this->getUriKey($uri, self::KEY_RO)));
        $state = $item->get();
        assert($state instanceof ResourceState || $state === null);

        return $state;
    }

    #[Override]
    public function getDonut(AbstractUri $uri): ResourceDonut|null
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $item = $this->guard(fn () => $this->roPool->getItem($key));
        $donut = $item->get();
        assert($donut instanceof ResourceDonut || $donut === null);

        return $donut;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function hasEtagFor(string $etag, AbstractUri $uri): bool
    {
        return $this->findEtag($etag, $this->identityFactory->locator($uri));
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function hasEtag(string $etag): bool
    {
        return $this->findEtag($etag, null);
    }

    /**
     * Does the entry this request would be served from still carry this validator?
     *
     * A validator is "K.H". K and the request's vary values name the entry, so one read decides,
     * and another variant's validator finds this request's entry, not its own. $locator, when
     * given, is the K of the URI asked for.
     */
    private function findEtag(string $etag, string|null $locator): bool
    {
        foreach (array_unique(EntityTags::of($etag)) as $opaqueTag) {
            $entryLocator = $this->identityFactory->locatorOf($opaqueTag);
            if ($entryLocator === null || ($locator !== null && $entryLocator !== $locator)) {
                continue;
            }

            $key = $this->identityFactory->storageKey($entryLocator, self::KEY_RO);
            $state = $this->guard(fn () => $this->roPool->getItem($key))->get();
            assert($state instanceof ResourceState || $state === null);
            if (EntityTags::of($state?->headers[Header::ETAG] ?? '') === [$opaqueTag]) {
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
        return true;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function invalidateTags(array $tags): bool
    {
        $start = hrtime(true);
        $roOk = $this->guard(fn (): bool => $this->roPool->invalidateTags($tags));

        // The outcome is logged before the failure is raised, and the local pools are invalidated
        // first, so a caller that retries does not double-invalidate.
        $purgerError = null;
        try {
            ($this->purger)(implode(' ', $tags));
        } catch (Throwable $e) {
            $purgerError = $e;
        }

        $result = new InvalidateContext(
            $tags,
            roPoolInvalidated: $roOk,
            etagPoolInvalidated: true,
            cdnStatus: $purgerError === null ? $this->getCdnOkStatus() : 'failed',
            durationMs: round((hrtime(true) - $start) / 1_000_000, 3),
        );

        $this->logInvalidation($result, $tags);

        if ($purgerError !== null) {
            // Fail-closed, and typed like the rest of the cache path: a command or a direct call
            // has no catch, so this reaches the caller and the write is not reported as done. The
            // automatic write inside a GET does catch it, and degrades - that request changed
            // nothing, so nothing at the edge became stale by it.
            throw new CacheStoreException(previous: $purgerError);
        }

        return $roOk;
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

        return $this->saveResourceState($ro, $body, null, $ttl, SaveValueContext::class);
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

        return $this->saveResourceState($ro, $body, $ro->view, $ttl, SaveViewContext::class);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
        // Despite the legacy parameter name (kept for BC), this argument carries the donut
        // template entry TTL (putStatic passes $ttl, putDonut passes $donutTtl), never a CDN s-maxage.
        $sMaxAge = $this->nonNegative($sMaxAge);
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $headerKeys = (new TagSet($headerKeys))->toArray();
        $saved = $this->saveToPool($key, $donut, $this->roPool, $headerKeys, $sMaxAge);
        // saved=false is logged, not asserted: a quiet store failure must stay observable
        // in the log (an assert here would throw AFTER the event, contradicting it).
        $this->logger->event(new SaveDonutContext((string) $uri, $headerKeys, $sMaxAge, $saved));
    }

    #[Override]
    public function saveDonutView(ResourceObject $ro, int|null $ttl, array|null $tags = null): bool
    {
        $ttl = $this->nonNegative($ttl);

        return $this->saveResourceState($ro, [], $ro->view, $ttl, SaveDonutViewContext::class, $tags);
    }

    /**
     * @param class-string<SaveValueContext>|class-string<SaveViewContext>|class-string<SaveDonutViewContext> $contextClass
     * @param list<string>|null                                                                               $tags
     */
    private function saveResourceState(ResourceObject $ro, mixed $body, string|null $view, int|null $ttl, string $contextClass, array|null $tags = null): bool
    {
        $this->locateEtag($ro);
        $resourceState = ResourceState::create($ro, $body, $view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro, $tags);
        $saved = $this->saveToPool($key, $resourceState, $this->roPool, $tags, $ttl);
        $this->logger->event(new $contextClass((string) $ro->uri, $tags, $ttl, $saved));

        return $saved;
    }

    /**
     * Prefix the validator with the K of the entry about to hold it, so a later If-None-Match
     * finds that entry by itself. Any EtagSetterInterface works: K is added here, not there.
     */
    private function locateEtag(ResourceObject $ro): void
    {
        if (! isset($ro->headers[Header::ETAG])) {
            return;
        }

        $etag = $ro->headers[Header::ETAG];
        $weak = str_starts_with($etag, 'W/') ? 'W/' : '';
        $opaque = trim(substr($etag, strlen($weak)), '"');
        $locator = $this->identityFactory->locator($ro->uri);
        if (str_starts_with($opaque, $locator . '.')) {
            return;
        }

        $ro->headers[Header::ETAG] = $weak . '"' . $locator . '.' . $opaque . '"';
    }

    /** @param list<string> $tags */
    private function saveToPool(string $key, mixed $value, TagAwareAdapterInterface $pool, array $tags, int|null $ttl): bool
    {
        return $this->guard(fn (): bool => ($this->saver)($key, $value, $pool, $tags, $ttl));
    }

    /**
     * @param list<string>|null $surrogateKeys null reads the Surrogate-Key header
     *
     * @return list<string>
     */
    private function getTags(ResourceObject $ro, array|null $surrogateKeys = null): array
    {
        // An ETag is never an invalidation tag: it is content-versioned, so each version
        // would leave a tag Set that nothing invalidates and a volatile-* eviction policy
        // never reclaims.
        $surrogateKeys ??= isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];

        return (new TagSet($this->cacheTags->tags($ro, $surrogateKeys)))->toArray();
    }

    private function nonNegative(int|null $ttl): int|null
    {
        return $ttl === null ? null : max(0, $ttl);
    }

    private function getUriKey(AbstractUri $uri, string $type): string
    {
        return $this->identityFactory->storageKey($this->identityFactory->locator($uri), $type);
    }

    #[Override]
    public function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, int|null $ttl): void
    {
    }

    /**
     * Run a store interaction, and let only its failure be the kind a cache path may swallow
     *
     * An interceptor degrades rather than failing a request whose response is already correct, but
     * `catch (Throwable)` there swallowed everything a write touches - a renderer with a bug, a
     * logic error introduced later. Wrapping here is what lets the catch be narrow: what the pool
     * raises arrives as CacheStoreException, and everything else keeps travelling.
     *
     * The CDN purge in invalidateTags() is raised by hand instead: its outcome is logged and the
     * local pools invalidated before it leaves, which a wrapper around the call cannot order.
     *
     * @param callable(): T $io
     *
     * @return T
     *
     * @template T
     */
    private function guard(callable $io): mixed
    {
        try {
            return $io();
        } catch (Throwable $e) {
            throw new CacheStoreException(previous: $e);
        }
    }

    public function __serialize(): array
    {
        return [
            'logger' => $this->logger,
            'purger' => $this->purger,
            'saver' => $this->saver,
            'roProvider' => $this->roPoolProvider,
            'identityFactory' => $this->identityFactory,
            'cacheTags' => $this->cacheTags,
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
        $this->saver = $data['saver'];
        $this->identityFactory = $data['identityFactory'];
        $this->cacheTags = $data['cacheTags'];
        // Stateless, so rebuilt rather than carried: left out of the payload it stays
        // uninitialized, and the first save after a compiled-injector restore throws.
        $this->evaluateBody = new ResourceBodyEvaluator();
        $this->initializePools($data['roProvider']);
    }
}
