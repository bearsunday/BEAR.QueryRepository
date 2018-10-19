<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;

final class MobileEtagSetter implements EtagSetterInterface
{
    public function __invoke(ResourceObject $ro, int $time = null, HttpCache $httpCache = null)
    {
        unset($httpCache);
        // etag]
        $ro->headers['ETag'] = (string) \crc32($this->getDevice() . \serialize($ro->view) . \serialize($ro->body));
        // time
        $time = $time === null ? \time() : $time;
        $ro->headers['Last-Modified'] = \gmdate('D, d M Y H:i:s', $time) . ' GMT';
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
