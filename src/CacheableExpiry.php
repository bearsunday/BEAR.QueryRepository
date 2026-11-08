<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;

use function is_array;
use function max;
use function sprintf;
use function strtotime;
use function time;

/**
 * The lifetime a #[Cacheable] declares: a body field naming the expiry time wins, then an explicit
 * second count, then the preset
 *
 * @internal
 */
final readonly class CacheableExpiry
{
    public function __construct(
        private Expiry $expiry,
    ) {
    }

    public function seconds(ResourceObject $ro, Cacheable $cacheable): int
    {
        if ($cacheable->expiryAt !== '') {
            return $this->secondsUntilExpiryAt($ro, $cacheable);
        }

        // A user-supplied expirySecond may be negative; the schemas declare "minimum": 0
        return max(0, $cacheable->expirySecond ?: $this->expiry->getTime($cacheable->expiry));
    }

    /** The 'never' preset declares no lifetime: the entry lives until a write invalidates it */
    public function isUntilInvalidated(Cacheable $cacheable): bool
    {
        return $cacheable->expiryAt === '' && $cacheable->expirySecond === 0 && $cacheable->expiry === 'never';
    }

    private function secondsUntilExpiryAt(ResourceObject $ro, Cacheable $cacheable): int
    {
        if (! is_array($ro->body) || ! isset($ro->body[$cacheable->expiryAt])) {
            $msg = sprintf('%s::%s', $ro::class, $cacheable->expiryAt);

            throw new ExpireAtKeyNotExists($msg);
        }

        /** @var string $expiryAt */
        $expiryAt = $ro->body[$cacheable->expiryAt];

        // A past expiryAt yields no lifetime to set, which the storage stores as "no expiry"
        return max(0, (int) strtotime($expiryAt) - time());
    }
}
