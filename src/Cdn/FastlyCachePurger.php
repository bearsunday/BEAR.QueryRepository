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
    private PurgeApi $purgeApi;
    private string $fastlyServiceId;
    private bool $enableSoftPurge;

    /**
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     * @Named("fastlyServiceId=fastlyServiceId,enableSoftPurge=fastlyEnableSofrPurge")
     */
    public function __construct(
        PurgeApi $purgeApi,
        #[Named('fastlyServiceId')] string $fastlyServiceId,
        #[Named('fastlyEnableSoftPurge')] bool $enableSoftPurge
    ) {
        $this->purgeApi = $purgeApi;
        $this->fastlyServiceId = $fastlyServiceId;
        $this->enableSoftPurge = $enableSoftPurge;
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
