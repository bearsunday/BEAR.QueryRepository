<?php

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\NoHttpCache;
use BEAR\RepositoryModule\Annotation\Purge;
use BEAR\RepositoryModule\Annotation\Refresh;
use BEAR\Resource\ResourceObject;

/**
 * @Cacheable
 * @NoHttpCache
 */
#[Cacheable('never', expirySecond: 30, expiryAt: 'expiry_column', type: 'view', update: true)]
#[NoHttpCache]
class FakeAttributes extends ResourceObject
{
    /**
     * @Refresh
     * @Purge
     */
    #[Refresh, Purge]
    public function onGet(): static
    {
    }
}
