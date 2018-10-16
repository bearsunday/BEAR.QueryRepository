<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

interface EtagSetterInterface
{
    /**
     * Set Etag
     */
    public function __invoke(ResourceObject $resourceObject, int $time = null, HttpCache $httpCache = null);
}
