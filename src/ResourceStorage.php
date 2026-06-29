<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\EtagPool;
use BEAR\RepositoryModule\Annotation\ResourceObjectPool;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Di\Di\Set;
use Ray\Di\ProviderInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Throwable;

use function assert;
use function explode;
use function implode;
use function is_array;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * @psalm-type Props = array{
 *     purger:PurgerInterface,
 *     uriTag: UriTag,
 *     cacheTags: CacheTags,
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
        private PurgerInterface $purger,
        private UriTagInterface $uriTag,
        private CacheTags $cacheTags,
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
    public function deleteEtag(AbstractUri $uri): bool
    {
        // Clears only this URI's ETag entries (etag pool), so a re-cached resource does not
        // serve a stale 304. It does NOT touch the resource-object pool (the body is
        // overwritten by the following save) nor the CDN, nor cascade to dependents — that
        // full invalidation is invalidateUri(), used by an explicit purge.
        return $this->etagPool->invalidateTags([($this->uriTag)($uri)]);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function invalidateUri(AbstractUri $uri): InvalidateResult
    {
        return $this->invalidateTags([($this->uriTag)($uri)]);
    }

    /**
     * {@inheritDoc}
     *
     * Performs the invalidation and reports the per-target outcome. Logging, the
     * top-level scope, and the fail-closed re-throw on CDN failure are the logging
     * decorator's responsibility (see LoggableResourceStorage).
     */
    #[Override]
    public function invalidateTags(array $tags): InvalidateResult
    {
        $roOk = $this->roPool->invalidateTags($tags);
        $etagOk = $this->etagPool->invalidateTags($tags);

        $cdnError = null;
        try {
            ($this->purger)(implode(' ', $tags));
        } catch (Throwable $e) {
            $cdnError = $e;
        }

        return new InvalidateResult($tags, $roOk, $etagOk, $cdnError);
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
        $tags = $this->cacheTags->ofResource($ro);

        return $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
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
        $tags = $this->cacheTags->ofResource($ro);

        return $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $result = $this->saver->__invoke($key, $donut, $this->roPool, $headerKeys, $sMaxAge);
        assert($result, 'Donut save failed.');
    }

    #[Override]
    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool
    {
        $resourceState = ResourceState::create($ro, [], $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->cacheTags->ofResource($ro);

        return $this->saver->__invoke($key, $resourceState, $this->roPool, $tags, $ttl);
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
        $tags = $this->cacheTags->ofEtag($uri, $surrogateKeys);
        $this->saver->__invoke($etag, 'etag', $this->etagPool, $tags, $ttl);
    }

    public function __serialize(): array
    {
        return [
            'purger' => $this->purger,
            'uriTag' => $this->uriTag,
            'cacheTags' => $this->cacheTags,
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
        $this->purger = $data['purger'];
        $this->uriTag = $data['uriTag'];
        $this->cacheTags = $data['cacheTags'];
        $this->saver = $data['saver'];
        $this->serverContext = $data['serverContext'];
        $this->initializePools($data['roProvider'], $data['etagProvider']);
    }
}
