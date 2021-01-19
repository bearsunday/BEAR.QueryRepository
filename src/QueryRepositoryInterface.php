<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

/**
 * @psalm-import-type ResourceState from ResourceStorageInterface
 */
interface QueryRepositoryInterface
{
    /**
     * @return bool Is successfully stored
     */
    public function put(ResourceObject $ro);

    /**
     * @psalm-return ResourceState|false
     * @phpstan-return array{0: AbstractUri, 1: int, 2: non-empty-array<string, mixed>, 3: mixed, 4: mixed}|false
     */
    public function get(AbstractUri $uri);

    /**
     * @return bool Is successfully deleted
     */
    public function purge(AbstractUri $uri);
}
