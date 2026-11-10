<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\SurrogateKeyOversizeContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use Closure;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;

/**
 * Marks a resource object while QueryRepository stores it, and sets the headers it is stored with
 *
 * @internal
 */
final readonly class PutTracker
{
    public function __construct(
        private HeaderSetter $headerSetter,
        private PutInProgress $putInProgress = new PutInProgress(),
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
    }

    /**
     * @param Closure(): bool $store
     *
     * @return bool what $store returns
     */
    public function storing(ResourceObject $ro, Closure $store): bool
    {
        $this->putInProgress->begin($ro);
        try {
            return $store();
        } finally {
            $this->putInProgress->end($ro);
        }
    }

    /** Records surrogate_key_oversize when the Surrogate-Key it leaves is over a Fastly limit */
    public function setHeaders(ResourceObject $ro, string|null $cacheControl, HttpCache|null $httpCache): void
    {
        ($this->headerSetter)($ro, $cacheControl, $httpCache);
        $oversize = SurrogateKeyOversizeContext::of($ro);
        if ($oversize !== null) {
            $this->logger->event($oversize);
        }
    }
}
