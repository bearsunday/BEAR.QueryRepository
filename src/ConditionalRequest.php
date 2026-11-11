<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractUri;
use BEAR\Sunday\Provide\Transfer\ConditionalResponse;
use BEAR\Sunday\Provide\Transfer\ConditionalResponseInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Throwable;

use function hrtime;
use function is_string;
use function round;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/** @internal */
final class ConditionalRequest
{
    /** @var array<string, string> the headers of the entry that validated the last request */
    private array $validated = [];

    public function __construct(
        private readonly ResourceStorageInterface $storage,
        #[CacheLog]
        private readonly SemanticLoggerInterface $logger,
        private readonly ConditionalResponseInterface $conditionalResponse = new ConditionalResponse(),
    ) {
    }

    /**
     * The headers of the 304 answering the request last found not modified
     *
     * Those of the entry that validated it, taken once; none when the storage cannot hand the
     * entry over.
     *
     * @return array<string, string>
     */
    public function notModifiedHeaders(): array
    {
        $validated = $this->validated;
        $this->validated = [];

        return $this->conditionalResponse->getOutput($validated)->headers;
    }

    private function validate(string $ifNoneMatch, AbstractUri|null $uri): bool
    {
        $this->validated = [];
        if (! $this->storage instanceof ResourceStorage) {
            return $uri !== null && $this->storage instanceof ScopedValidatorInterface ? $this->storage->hasEtagFor($ifNoneMatch, $uri) : $this->storage->hasEtag($ifNoneMatch);
        }

        $state = $this->storage->validatedEntry($ifNoneMatch, $uri);
        if ($state === null) {
            return false;
        }

        $this->validated = $state->headers;

        return true;
    }

    /**
     * Recorded as its own conditional_request scope: a hit is the 304 decision that no get
     * scope ever shows. No If-None-Match presents nothing, so nothing is recorded.
     *
     * @param array<array-key, mixed> $server
     */
    public function isNotModified(
        string|null $ifNoneMatch,
        array $server,
    ): bool {
        if ($ifNoneMatch === null) {
            $this->validated = [];

            return false;
        }

        // What answering without running the resource cost: this is the whole request on a hit.
        $openId = $this->logger->open(new ConditionalRequestContext($ifNoneMatch));
        $start = hrtime(true);
        try {
            $hit = $this->validate($ifNoneMatch, null);
        } catch (CacheStoreException $e) {
            // An unreadable validator does not match: serve the request in full rather than
            // fail it with a 500 for a down ETag store.
            $cause = $e->getPrevious() ?? $e;
            $this->logger->event(new CacheErrorContext($this->requestUri($server), 'read', $cause->getMessage(), $cause::class));
            $this->logger->close(new CacheMissContext('etag', round((hrtime(true) - $start) / 1_000_000, 3)), $openId);
            $this->triggerWarning($e);

            return false;
        }

        $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
        $this->logger->close($hit ? new CacheHitContext('etag', $durationMs) : new CacheMissContext('etag', $durationMs), $openId);

        return $hit;
    }

    /**
     * A validator issued for another URI is not this representation's, so the request is
     * answered in full.
     *
     * @param array<array-key, mixed> $server
     */
    public function isNotModifiedFor(
        AbstractUri $uri,
        string|null $ifNoneMatch,
        array $server,
    ): bool {
        if ($ifNoneMatch === null || ! $this->storage instanceof ScopedValidatorInterface) {
            // No validator leaves nothing to scope. A storage that cannot scope answers the older
            // question; a reader of the log can still see which validator was presented, but not
            // which question was asked.
            return $this->isNotModified($ifNoneMatch, $server);
        }

        $openId = $this->logger->open(new ConditionalRequestContext($ifNoneMatch));
        $start = hrtime(true);
        try {
            $hit = $this->validate($ifNoneMatch, $uri);
        } catch (CacheStoreException $e) {
            // The same decision as the unscoped answer, against the URI that was asked for.
            $cause = $e->getPrevious() ?? $e;
            $this->logger->event(new CacheErrorContext((string) $uri, 'read', $cause->getMessage(), $cause::class));
            $this->logger->close(new CacheMissContext('etag', round((hrtime(true) - $start) / 1_000_000, 3)), $openId);
            $this->triggerWarning($e);

            return false;
        }

        $durationMs = round((hrtime(true) - $start) / 1_000_000, 3);
        $this->logger->close($hit ? new CacheHitContext('etag', $durationMs) : new CacheMissContext('etag', $durationMs), $openId);

        return $hit;
    }

    /**
     * The request URI when the SAPI array carries one
     *
     * The interface shape names only the validator key, but the real $_SERVER carries the
     * whole request; empty means no URI was resolvable at the transfer boundary.
     *
     * @param array<array-key, mixed> $server
     */
    private function requestUri(array $server): string
    {
        /** @var mixed $uri */
        $uri = $server['REQUEST_URI'] ?? '';

        return is_string($uri) ? $uri : '';
    }

    /**
     * A failure on the cache path degrades to a warning rather than an exception
     *
     * The host's own monitoring is what notices a pool outage; the log records it at the
     * point it happened.
     */
    private function triggerWarning(Throwable $e): void
    {
        $cause = $e->getPrevious() ?? $e;
        trigger_error(sprintf('%s: %s in %s:%s', $cause::class, $cause->getMessage(), $cause->getFile(), $cause->getLine()), E_USER_WARNING);
    }
}
