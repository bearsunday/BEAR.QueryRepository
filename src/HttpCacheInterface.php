<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

interface HttpCacheInterface
{
    /**
     * Is not modified ? (RFC7232 4.1)
     *
     * Indicates that a conditional GET or HEAD request has been received and would have resulted in a 200
     * (OK) response if it were not for the fact that the condition evaluated to false.
     *
     * @https://tools.ietf.org/html/rfc7232#section-4.1
     */
    public function isNotModified(array $server) : bool;

    /**
     * Transfer status code 304 to the client
     *
     * @return void
     */
    public function transfer();
}
