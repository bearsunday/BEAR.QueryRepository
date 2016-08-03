<?php
/**
 * This file is part of the BEAR.QueryRepository package.
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

        if ($resourceObject->code !== 200) {
            return;
        }
        $resourceObject->headers['ETag'] = (string) crc32(serialize($resourceObject->view) . serialize($resourceObject->body));
        $resourceObject->headers['Last-Modified'] = gmdate("D, d M Y H:i:s", $time) . ' GMT';
    }
}
