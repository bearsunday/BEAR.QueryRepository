<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\AbstractCacheControl;
use BEAR\Resource\ResourceObject;

class EtagSetter implements EtagSetterInterface
{
    public function __invoke(ResourceObject $resourceObject, int $time = null, AbstractCacheControl $httpCacche = null)
    {
        $time = $time !== null ?: \time();

        if ($resourceObject->code !== 200) {
            return;
        }
        $resourceObject->headers['ETag'] = $this->getEtag($resourceObject, $httpCacche);
        $resourceObject->headers['Last-Modified'] = \gmdate('D, d M Y H:i:s', $time) . ' GMT';
    }

    public function getEtagByPartialBody(AbstractCacheControl $httpCacche) : string
    {
        $etag = '';
        foreach ($httpCacche->etag as $etagKey) {
            $phpServerKey = \sprintf('HTTP_%s', \strtoupper($etagKey));
            $etag .= \strtolower($_SERVER[$phpServerKey] ?? '');
        }

        return $etag;
    }

    public function getEtagByEitireView(ResourceObject $ro)
    {
        return \get_class($ro) . \serialize($ro->view);
    }

    /**
     * Return crc32 encoded Etag
     *
     * Is crc32 enough for Etag ?
     *
     * @see https://cloud.google.com/storage/docs/hashes-etags
     */
    private function getEtag(ResourceObject $ro, AbstractCacheControl $httpCacche = null) : string
    {
        $hasEtagKeys = $httpCacche instanceof AbstractCacheControl && $httpCacche->etag !== [];
        $etag = $hasEtagKeys ? $this->getEtagByPartialBody($httpCacche, $ro) : $this->getEtagByEitireView($ro);

        return (string) \crc32($etag);
    }
}
