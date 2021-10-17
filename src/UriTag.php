<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;

use function http_build_query;
use function ksort;
use function sprintf;
use function str_replace;

final class UriTag implements UriTagInterface
{
    public function __invoke(AbstractUri $uri): string
    {
        $query = $uri->query;
        ksort($query);

        return str_replace('/', '_', sprintf('%s_%s', $uri->path, http_build_query($query)));
    }
}
