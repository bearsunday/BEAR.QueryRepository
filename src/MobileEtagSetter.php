<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use Detection\MobileDetect;
use Override;

use function crc32;
use function gmdate;
use function serialize;
use function time;

final readonly class MobileEtagSetter implements EtagSetterInterface
{
    public function __construct(
        private ResourceBodyEvaluator $evaluateBody = new ResourceBodyEvaluator(),
    ) {
    }

    #[Override]
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null): void
    {
        unset($httpCache);
        // A validator on a non-200 makes ConditionalResponse::isModified() answer 304 for a
        // request whose If-None-Match matches, replacing the 301 or 204 with a Not Modified.
        // EtagSetter gates on 200 for that reason; these two did not.
        if ($ro->code !== 200) {
            return;
        }

        $ro->headers[Header::ETAG] = '"' . crc32($this->getDevice() . serialize($ro->view) . serialize(($this->evaluateBody)($ro->body))) . '"';
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, $time ?? time());
    }

    /**
     * Return ETag prefix by device
     */
    private function getDevice(): string
    {
        $detect = new MobileDetect();

        return $detect->isMobile() && ! $detect->isTablet() ? 'mobile' : 'pc';
    }
}
