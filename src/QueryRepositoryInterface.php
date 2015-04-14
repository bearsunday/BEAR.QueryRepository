<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;

interface QueryRepositoryInterface
{
    /**
     * @param ResourceObject $ro
     * @param int            $lifeTime
     *
     * @return bool Is successfully stored
     */
    public function put(ResourceObject $ro);

    /**
     * @param Uri $uri
     *
     * @return [$code, $headers, $body, $view]|false
     */
    public function get(Uri $uri);

    /**
     * @param Uri $uri
     *
     *  @return bool Is successfully deleted
     */
    public function purge(Uri $uri);
}
