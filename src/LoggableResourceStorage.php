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
use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Ray\Di\Di\Named;

use function hrtime;
use function round;

/**
 * Logging decorator for ResourceStorage
 *
 * Keeps the storage itself free of any logging concern: every cache write emits its
 * semantic-log event here, around a transparent delegation to the wrapped storage.
 * The tags reported for a save are recomputed with the same {@see CacheTags} the
 * storage uses, so the log records exactly what was stored.
 *
 * Reads (get / getDonut / hasEtag) are pass-through: a request's hit/miss is logged
 * at the interceptor / donut-repository layer where the request-level outcome lives,
 * not at the low-level pool read.
 */
final class LoggableResourceStorage implements ResourceStorageInterface
{
    public function __construct(
        #[Named('origin')]
        private readonly ResourceStorageInterface $storage,
        private readonly SemanticLoggerInterface $logger,
        private readonly CacheTags $cacheTags,
        private readonly UriTagInterface $uriTag,
    ) {
    }

    #[Override]
    public function hasEtag(string $etag): bool
    {
        return $this->storage->hasEtag($etag);
    }

    #[Override]
    public function saveEtag(AbstractUri $uri, string $etag, string $surrogateKeys, int|null $ttl): void
    {
        $this->storage->saveEtag($uri, $etag, $surrogateKeys, $ttl);
        $this->logger->event(new SaveEtagContext((string) $uri, $etag, $this->cacheTags->ofEtag($uri, $surrogateKeys)));
    }

    #[Override]
    public function deleteEtag(AbstractUri $uri)
    {
        return $this->invalidateTags([($this->uriTag)($uri)])->isInvalidated();
    }

    #[Override]
    public function get(AbstractUri $uri): ResourceState|null
    {
        return $this->storage->get($uri);
    }

    #[Override]
    public function getDonut(AbstractUri $uri): ResourceDonut|null
    {
        return $this->storage->getDonut($uri);
    }

    /**
     * {@inheritDoc}
     *
     * A top-level invalidation is a direct (non-AOP) call with no enclosing scope, so the
     * event would be dropped at flush. Root it in a manual_invalidate scope whose close
     * carries the outcome. Nested invalidations (inside a GET or a command) stay events.
     *
     * The CDN purge is fail-closed: a purge failure is logged (cdn=failed) and then
     * re-thrown so a write does not silently leave stale CDN content.
     */
    #[Override]
    public function invalidateTags(array $tags): InvalidateResult
    {
        $start = hrtime(true);
        $result = $this->storage->invalidateTags($tags);
        $context = new InvalidateContext(
            $result->tags,
            roPoolInvalidated: $result->roInvalidated,
            etagPoolInvalidated: $result->etagInvalidated,
            cdnPurged: $result->cdnError === null,
            durationMs: round((hrtime(true) - $start) / 1_000_000, 3),
        );

        if ($this->logger instanceof SafeSemanticLogger && $this->logger->isTopLevel()) {
            $openId = $this->logger->open(new ManualInvalidateContext($tags));
            $this->logger->close($context, $openId);
        } else {
            $this->logger->event($context);
        }

        if ($result->cdnError !== null) {
            throw $result->cdnError;
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @return bool
     */
    #[Override]
    public function saveValue(ResourceObject $ro, int $ttl)
    {
        $saved = $this->storage->saveValue($ro, $ttl);
        $this->logger->event(new SaveValueContext((string) $ro->uri, $this->cacheTags->ofResource($ro), $ttl));

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
        $saved = $this->storage->saveView($ro, $ttl);
        $this->logger->event(new SaveViewContext((string) $ro->uri, $ttl));

        return $saved;
    }

    #[Override]
    public function saveDonut(AbstractUri $uri, ResourceDonut $donut, int|null $sMaxAge, array $headerKeys): void
    {
        $this->storage->saveDonut($uri, $donut, $sMaxAge, $headerKeys);
        $this->logger->event(new SaveDonutContext((string) $uri, $sMaxAge));
    }

    #[Override]
    public function saveDonutView(ResourceObject $ro, int|null $ttl): bool
    {
        $saved = $this->storage->saveDonutView($ro, $ttl);
        $this->logger->event(new SaveDonutViewContext((string) $ro->uri, $this->cacheTags->ofResource($ro), $ttl));

        return $saved;
    }
}
