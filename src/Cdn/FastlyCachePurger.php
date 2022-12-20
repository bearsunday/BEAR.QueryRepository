<?php

declare(strict_types=1);

namespace BEAR\QueryRepository\Cdn;

use BEAR\QueryRepository\PurgerInterface;
use Fastly\Api\PurgeApi;
use Fastly\ApiException;
use Ray\Di\Di\Named;

use function explode;

final class FastlyCachePurger implements PurgerInterface
{
    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     * @Named("fastlyServiceId=fastlyServiceId,enableSoftPurge=fastlyEnableSofrPurge")
     */
    public function __construct(
        private PurgeApi $purgeApi,
        #[Named('fastlyServiceId')] private string $fastlyServiceId,
        #[Named('fastlyEnableSoftPurge')] private bool $enableSoftPurge,
    ) {
    }

    /**
     * @throws ApiException
     *
     * @see https://developer.fastly.com/reference/api/purging/
     */
    public function __invoke(string $tag): void
    {
        $this->purgeApi->bulkPurgeTag([
            'fastly_soft_purge' => (int) $this->enableSoftPurge,
            'service_id' => $this->fastlyServiceId,
            'purge_response' => ['surrogate_keys' => explode(' ', $tag)],
        ]);
    }
}
