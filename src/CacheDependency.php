<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\DependencyAfterSaveContext;
use BEAR\QueryRepository\Log\Context\DependsOnContext;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use ReflectionClass;

use function array_unique;
use function array_values;
use function explode;
use function implode;
use function sprintf;

/** @internal */
final readonly class CacheDependency implements CacheDependencyInterface
{
    private BaseUriTagInterface $baseUriTag;

    public function __construct(
        private UriTagInterface $uriTag,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
        BaseUriTagInterface|null $baseUriTag = null,
    ) {
        $this->baseUriTag = $baseUriTag ?? new BaseUriTag($uriTag);
    }

    #[Override]
    public function depends(ResourceObject $from, ResourceObject $to): void
    {
        // The group tag too, so a purge of the child's bare URI reaches a parent that embeds one of its variants.
        $childTags = sprintf('%s %s', ($this->uriTag)($to->uri), ($this->baseUriTag)($to));
        if (isset($to->headers[Header::SURROGATE_KEY])) {
            $childTags .= sprintf(' %s', $to->headers[Header::SURROGATE_KEY]);
        }

        // A #[DonutCache] child's default Surrogate-Key equals its URI tag.
        $childTagList = array_values(array_unique(explode(' ', $childTags)));

        // Accumulate across embedded children, deduped: #[Refresh] re-puts the instance
        // the rebuilding GET already stored, and this header ships to the CDN.
        $existing = isset($from->headers[Header::SURROGATE_KEY])
            ? explode(' ', $from->headers[Header::SURROGATE_KEY])
            : [];
        $from->headers[Header::SURROGATE_KEY] = implode(' ', array_unique([...$existing, ...$childTagList]));

        $this->logger->event(new DependsOnContext(
            (string) $from->uri,
            (string) $to->uri,
            $childTagList,
        ));

        if (isset($from->headers[Header::ETAG]) && self::storesValue($from)) {
            $this->logger->event(new DependencyAfterSaveContext((string) $from->uri, (string) $to->uri));
        }
    }

    /**
     * A value entry is stored, and its ETag set, before the page renders: an ETag here means the save has happened
     *
     * An ETag the resource set itself in onGet() reads the same.
     */
    private static function storesValue(ResourceObject $ro): bool
    {
        $attributes = (new ReflectionClass($ro))->getAttributes(Cacheable::class);

        return isset($attributes[0]) && $attributes[0]->newInstance()->type === 'value';
    }
}
