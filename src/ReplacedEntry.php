<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\PreWriteCleanupContext;
use BEAR\QueryRepository\Log\Context\PutSkippedContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\ResourceObject;
use Koriym\SemanticLogger\SemanticLoggerInterface;

/**
 * Drops the entry a write replaces or refuses to replace, and with it whatever embeds it. A write into an empty
 * slot replaces nothing: the other variants of the URI share its tag and must outlive it.
 *
 * @internal
 */
final readonly class ReplacedEntry
{
    public function __construct(
        #[CacheLog]
        private SemanticLoggerInterface $logger,
        private ResourceStorageInterface $storage,
        private UriTagInterface $uriTag,
    ) {
    }

    /** @return bool whether the value or view write may store */
    public function entry(ResourceObject $ro, Storability $storability): bool
    {
        if ($this->storage->get($ro->uri) !== null) {
            $this->drop($ro);
        }

        return $this->mayStore($ro, $storability);
    }

    /** @return bool whether the page or template write may store */
    public function page(ResourceObject $ro, Storability $storability): bool
    {
        if ($this->storage->get($ro->uri) !== null || $this->storage->getDonut($ro->uri) !== null) {
            $this->drop($ro);
        }

        return $this->mayStore($ro, $storability);
    }

    private function drop(ResourceObject $ro): void
    {
        $this->logger->event(new PreWriteCleanupContext((string) $ro->uri));
        $this->storage->invalidateTags([($this->uriTag)($ro->uri)]);
    }

    private function mayStore(ResourceObject $ro, Storability $storability): bool
    {
        $reason = match ($storability) {
            Storability::Shared => null,
            Storability::Unstorable => 'unstorable',
            Storability::Expired => 'expired',
        };
        if ($reason === null) {
            return true;
        }

        $this->logger->event(new PutSkippedContext((string) $ro->uri, $reason));

        return false;
    }
}
