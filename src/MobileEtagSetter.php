<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

final class MobileEtagSetter implements EtagSetterInterface
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
     * Return ETag prefix by device
     *
     * @return string
     */
    private function getDevice()
    {
        $detect = new \Mobile_Detect;

        return $detect->isMobile() && ! $detect->isTablet() ? 'mobile' : 'pc';
    }
}
