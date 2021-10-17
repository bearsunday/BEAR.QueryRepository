<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;

interface UriTagInterface
{
    public function __invoke(AbstractUri $uri): string;
}
