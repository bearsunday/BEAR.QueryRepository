<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

class EtagSetter implements EtagSetterInterface
{
    public function __invoke(ResourceObject $resourceObject, $time = null)
    {
        $time = ! is_null($time) ?: time();

        $view = (string) $resourceObject;
        $resourceObject->headers['Etag'] = (string) crc32($view);
        $resourceObject->headers['Last-Modified'] = gmdate("D, d M Y H:i:s", $time) . ' GMT';
    }
}
