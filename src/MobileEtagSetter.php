<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

class MobileEtagSetter implements EtagSetterInterface
{
    public function __invoke(ResourceObject $resourceObject, int $time = null, HttpCache $httpCache = null)
    {
        unset($httpCache);
        // etag]
        $resourceObject->headers['ETag'] = (string) \crc32($this->getDevice() . \serialize($resourceObject->view) . \serialize($resourceObject->body));
        // time
        $time = $time === null ? \time() : $time;
        $resourceObject->headers['Last-Modified'] = \gmdate('D, d M Y H:i:s', $time) . ' GMT';
    }

    /**
     * @return string
     */
    protected function getDevice()
    {
        $detect = new \Mobile_Detect;
        $mobileDeviceType = $detect->isMobile() && ! $detect->isTablet() ? 'mobile' : 'pc';

        return $mobileDeviceType;
    }
}
