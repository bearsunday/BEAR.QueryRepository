<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use Mobile_Detect;

use function crc32;
use function gmdate;
use function serialize;
use function time;

final class MobileEtagSetter implements EtagSetterInterface
{
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null): void
    {
        unset($httpCache);
        // etag]
        $ro->headers[Header::ETAG] = (string) crc32($this->getDevice() . serialize($ro->view) . serialize($ro->body));
        // time
        $time ??= time();
        $ro->headers[Header::LAST_MODIFIED] = gmdate('D, d M Y H:i:s', $time) . ' GMT';
    }

    /**
     * Return ETag prefix by device
     */
    private function getDevice(): string
    {
        $detect = new Mobile_Detect();

        return $detect->isMobile() && ! $detect->isTablet() ? 'mobile' : 'pc';
    }
}
