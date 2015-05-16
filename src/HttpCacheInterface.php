<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

interface HttpCacheInterface
{
    /**
     * Is not modified ?
     *
     * @param array $server
     *
     * @return bool
     */
    public function isNotModified(array $server);
}
