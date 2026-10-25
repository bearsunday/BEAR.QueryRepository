<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\ResourceObject;
use Override;

use function gmdate;

final readonly class DevEtagSetter implements EtagSetterInterface
{
    /**
     * {@inheritDoc}
     */
    #[Override]
    public function __invoke(ResourceObject $ro, int|null $time = null, HttpCache|null $httpCache = null)
    {
        // A validator on a non-200 makes ConditionalResponse::isModified() answer 304 for a
        // request whose If-None-Match matches, replacing the 301 or 204 with a Not Modified -
        // so this gates on 200, same as EtagSetter.
        if ($ro->code !== 200) {
            return;
        }

        $uriEtag = (new UriTag())($ro->uri);
        // Dev mode uses the URI itself as the ETag, instead of a content hash, so the stored
        // key is predictable while debugging.
        $ro->headers[Header::ETAG] = '"' . $uriEtag . '"';
        $ro->headers[Header::LAST_MODIFIED] = gmdate(Header::RFC7231, 0);
    }
}
