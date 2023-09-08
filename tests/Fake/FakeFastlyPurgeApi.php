<?php

namespace BEAR\QueryRepository;

use Fastly\Api\PurgeApi;

final class FakeFastlyPurgeApi extends PurgeApi
{
    /**
     * @var array
     */
    public array $logs = [];

    /**
     * @param array<string,mixed> $options
     */
    public function bulkPurgeTag($options)
    {
        $this->logs[] = $options;
    }
}
