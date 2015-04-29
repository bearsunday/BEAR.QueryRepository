<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\Resource\ResourceObject;

class MobileEtagSetter implements EtagSetterInterface
{
    public function __invoke(ResourceObject $resourceObject, $time = null)
    {
        // etag]
        $resourceObject->headers['Etag'] = (string) crc32($this->getDevice() . (string) $resourceObject);
        // time
        $time = ! is_null($time) ?: time();
        $resourceObject->headers['Last-Modified'] = gmdate("D, d M Y H:i:s", $time) . ' GMT';
    }

    /**
     * @return string
     */
    protected function getDevice()
    {
        $detect = new \Mobile_Detect;
        $mobileDeviceType =  $detect->isMobile() && ! $detect->isTablet() ? 'mobile' : 'pc';

        return $mobileDeviceType;
    }
}
