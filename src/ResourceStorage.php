<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\InvalidateContext;
use BEAR\QueryRepository\Log\Context\ManualInvalidateContext;
use BEAR\QueryRepository\Log\Context\SaveDonutContext;
use BEAR\QueryRepository\Log\Context\SaveDonutViewContext;
use BEAR\QueryRepository\Log\Context\SaveEtagContext;
use BEAR\QueryRepository\Log\Context\SaveValueContext;
use BEAR\QueryRepository\Log\Context\SaveViewContext;
use BEAR\QueryRepository\Log\SafeSemanticLogger;
use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
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
use function is_array;
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
final class ResourceStorage implements ResourceStorageInterface
{
    /**
     * Resource object cache prefix
     */
    private const KEY_RO = 'ro-';

    /**
     * Resource static cache prifix
     */
    private const KEY_DONUT = 'donut-';

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
        private SemanticLoggerInterface $logger,
        private PurgerInterface $purger,
        private UriTagInterface $uriTag,
        private ResourceStorageSaver $saver,
        private ServerContextInterface $serverContext,
        #[Set(TagAwareAdapterInterface::class, ResourceObjectPool::class)]
        ProviderInterface $roPoolProvider,
        #[Set(TagAwareAdapterInterface::class, EtagPool::class)]
        ProviderInterface $etagPoolProvider,
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
     */
    #[Override]
    public function hasEtag(string $etag): bool
    {
        return $this->etagPool->hasItem($etag);
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
            cdnPurged: $purgerError === null,
            durationMs: round((hrtime(true) - $start) / 1_000_000, 3),
        );

        $this->logInvalidation($result, $tags);

        if ($purgerError !== null) {
            throw $purgerError;
        }

        return $roOk && $etagOk;
    }

    /**
     * Record an invalidation outcome
     *
     * A top-level invalidation is a direct (non-AOP) call with no enclosing scope, so the
     * event would be dropped at flush. Root it in a manual_invalidate scope whose close
     * carries the outcome. Nested invalidations (inside a GET or a command) stay events.
     *
     * @param list<string> $tags
     */
    private function logInvalidation(InvalidateContext $result, array $tags): void
    {
        if ($this->logger instanceof SafeSemanticLogger && $this->logger->isTopLevel()) {
            $openId = $this->logger->open(new ManualInvalidateContext($tags));
            $this->logger->close($result, $openId);

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
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $value = ResourceState::create($ro, $body, null);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $saved = $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
        $this->logger->event(new SaveValueContext((string) $ro->uri, $tags, $ttl));

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
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $value = ResourceState::create($ro, $body, $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $saved = $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
        $this->logger->event(new SaveViewContext((string) $ro->uri, $ttl));

        return $saved;
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $result = $this->saver->__invoke($key, $donut, $this->roPool, $headerKeys, $sMaxAge);
        $this->logger->event(new SaveDonutContext((string) $uri, $sMaxAge));
        assert($result, 'Donut save failed.');
    }

    #[Override]
    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool
    {
        $resourceState = ResourceState::create($ro, [], $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $saved = $this->saver->__invoke($key, $resourceState, $this->roPool, $tags, $ttl);
        $this->logger->event(new SaveDonutViewContext((string) $ro->uri, $tags, $ttl));

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

    private function evaluateBody(mixed $body): mixed
    {
        if (! is_array($body)) {
            return $body;
        }

        /** @psalm-suppress MixedAssignment $item */
        foreach ($body as &$item) {
            if ($item instanceof RequestInterface) {
                $item = ($item)();
            }

            if ($item instanceof ResourceObject) {
                $item->body = $this->evaluateBody($item->body);
            }
        }

        return $body;
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
        $tags = $surrogateKeys !== '' ? explode(' ', $surrogateKeys) : [];
        $tags[] = (new UriTag())($uri);
        /** @var list<string> $uniqueTags */
        $uniqueTags = array_values(array_unique($tags));
        // Sanitize etag to remove reserved characters
        $this->saver->__invoke($etag, 'etag', $this->etagPool, $uniqueTags, $ttl);
        $this->logger->event(new SaveEtagContext((string) $uri, $etag, $uniqueTags));
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
        $this->initializePools($data['roProvider'], $data['etagProvider']);
    }
}
