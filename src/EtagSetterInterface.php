<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
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
