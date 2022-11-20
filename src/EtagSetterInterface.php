<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

interface EtagSetterInterface
{
    /**
     * Set Etag
     *
     * @return void
     */
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null);
}
