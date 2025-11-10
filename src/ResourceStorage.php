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

use function array_merge;
use function array_unique;
use function assert;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function strtoupper;

/**
 * @psalm-type Props = array{
 *     logger: RepositoryLoggerInterface,
 *     purger:PurgerInterface,
 *     uriTag: UriTag,
 *     saver: ResourceStorageSaver,
 *     roProvider:ProviderInterface<TagAwareAdapterInterface>,
 *     etagProvider: ProviderInterface<TagAwareAdapterInterface>
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
        private RepositoryLoggerInterface $logger,
        private PurgerInterface $purger,
        private UriTagInterface $uriTag,
        private ResourceStorageSaver $saver,
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
        $tag = $tags !== [] ? implode(' ', $tags) : '';
        $this->logger->log('invalidate-etag tags:%s', $tag);
        $valid1 = $this->roPool->invalidateTags($tags);
        $valid2 = $this->etagPool->invalidateTags($tags);
        ($this->purger)(implode(' ', $tags));

        return $valid1 && $valid2;
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
        $this->logger->log('save-value uri:%s tags:%s ttl:%s', $ro->uri, $tags, $ttl);

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
        $this->logger->log('save-view uri:%s ttl:%s', $ro->uri, $ttl);
        /** @psalm-suppress MixedAssignment $body */
        $body = $this->evaluateBody($ro->body);
        $value = ResourceState::create($ro, $body, $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);

        return $this->saver->__invoke($key, $value, $this->roPool, $tags, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
        $key = $this->getUriKey($uri, self::KEY_DONUT);
        $this->logger->log('save-donut uri:%s s-maxage:%s', $uri, $sMaxAge);
        $result = $this->saver->__invoke($key, $donut, $this->roPool, $headerKeys, $sMaxAge);
        assert($result, 'Donut save failed.');
    }

    #[Override]
    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool
    {
        $resourceState = ResourceState::create($ro, [], $ro->view);
        $key = $this->getUriKey($ro->uri, self::KEY_RO);
        $tags = $this->getTags($ro);
        $this->logger->log('save-donut-view uri:%s surrogate-keys:%s s-maxage:%s', $ro->uri, $tags, $ttl);

        return $this->saver->__invoke($key, $resourceState, $this->roPool, $tags, $ttl);
    }

    /** @return list<string> */
    private function getTags(ResourceObject $ro): array
    {
        $etag = $ro->headers['ETag'];
        $tags = [$etag, ($this->uriTag)($ro->uri)];
        if (isset($ro->headers[Header::SURROGATE_KEY])) {
            $tags = array_merge($tags, explode(' ', $ro->headers[Header::SURROGATE_KEY]));
        }

        /** @var list<string> $uniqueTags */
        $uniqueTags = array_unique($tags);

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
        return $type . ($this->uriTag)($uri) . (isset($_SERVER['X_VARY']) ? $this->getVary() : '');
    }

    private function getVary(): string
    {
        $xvary = $_SERVER['X_VARY'];
        /** @psalm-suppress RedundantCast */
        $varys = explode(',', (string) $xvary); // @phpstan-ignore-line
        $varyString = '';
        foreach ($varys as $vary) {
            $phpVaryKey = sprintf('X_%s', strtoupper($vary));
            if (isset($_SERVER[$phpVaryKey]) && is_string($_SERVER[$phpVaryKey])) {
                $varyString .= $_SERVER[$phpVaryKey];
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
        $uniqueTags = array_unique($tags);
        $this->logger->log('save-etag uri:%s etag:%s surrogate-keys:%s', $uri, $etag, $uniqueTags);
        // Sanitize etag to remove reserved characters
        $this->saver->__invoke($etag, 'etag', $this->etagPool, $uniqueTags, $ttl);
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
        $this->initializePools($data['roProvider'], $data['etagProvider']);
    }
}
