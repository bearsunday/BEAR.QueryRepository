<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractUri;
use BEAR\Resource\ResourceObject;

interface QueryRepositoryInterface
{
    /**
     * @param ResourceObject $ro
     *
     * @return bool Is successfully stored
     */
    public function put(ResourceObject $ro);

    /**
     * @param AbstractUri $uri
     *
     * @return [$code, $headers, $body, $view]|false
     */
    public function get(AbstractUri $uri);

    /**
     * @param AbstractUri $uri
     *
     *  @return bool Is successfully deleted
     */
    public function purge(AbstractUri $uri);
}
