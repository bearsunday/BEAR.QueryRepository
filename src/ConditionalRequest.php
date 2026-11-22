<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractUri;
use BEAR\Sunday\Provide\Transfer\ConditionalResponse;
use BEAR\Sunday\Provide\Transfer\ConditionalResponseInterface;
use Koriym\SemanticLogger\SemanticLoggerInterface;

use function hrtime;
use function is_string;
use function round;

/** @internal */
final class ConditionalRequest
{
    /** @var array<string, string> the headers of the entry that validated the last request */
    private array $validated = [];

    public function __construct(
        private readonly ResourceStorageInterface $storage,
        #[CacheLog]
        private readonly SemanticLoggerInterface $logger,
        private readonly CacheFailure $failure,
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

        return $this->answer($ifNoneMatch, null, $this->requestUri($server));
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

        return $this->answer($ifNoneMatch, $uri, (string) $uri);
    }

    /**
     * An unreadable validator does not match: the request is served in full, not failed with a 500 for a store that is down
     *
     * @param string $asked the URI a failed read is recorded against
     */
    private function answer(string $ifNoneMatch, AbstractUri|null $uri, string $asked): bool
    {
        // What answering without running the resource cost: this is the whole request on a hit.
        $openId = $this->logger->open(new ConditionalRequestContext($ifNoneMatch));
        $start = hrtime(true);
        try {
            $hit = $this->validate($ifNoneMatch, $uri);
        } catch (CacheStoreException $e) {
            $this->failure->record($asked, 'read', $e);
            $this->logger->close(new CacheMissContext('etag', round((hrtime(true) - $start) / 1_000_000, 3)), $openId);
            $this->failure->warn($e);

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
}
