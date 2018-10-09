<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\ExpireAtKeyNotExists;
use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\Cache;

class QueryRepository implements QueryRepositoryInterface
{
    const ETAG_BY_URI = 'etag-by-uri';

    /**
     * @var Cache
     */
    private $kvs;

    /**
     * @var Reader
     */
    private $reader;

    /**
     * @var Expiry
     */
    private $expiry;

    /**
     * @var EtagSetterInterface
     */
    private $setEtag;

    /**
     * @Storage("kvs")
     */
    public function __construct(
        EtagSetterInterface $setEtag,
        Cache $kvs,
        Reader $reader,
        Expiry $expiry
    ) {
        $this->setEtag = $setEtag;
        $this->reader = $reader;
        $this->kvs = $kvs;
        $this->expiry = $expiry;
    }

    /**
     * {@inheritdoc}
     */
    public function put(ResourceObject $ro)
    {
        $ro->toString();
        ($this->setEtag)($ro);
        if (isset($ro->headers['ETag'])) {
            $this->updateEtagDatabase($ro);
        }
        /* @var $cacheable Cacheable */
        $cacheable = $this->getCacheable($ro);
        $body = $this->evaluateBody($ro->body);
        $lifeTime = $this->getExpiryTime($ro, $cacheable);
        $this->setMaxAge($ro, $lifeTime);
        if ($cacheable instanceof Cacheable && $cacheable->type === 'view') {
            if (! $ro->view) {
                // render
                $ro->view = $ro->toString();
            }

            return $this->kvs->save((string) $ro->uri, [$ro->uri, $ro->code, $ro->headers, $body, $ro->view], $lifeTime);
        }
        // "value" cache type
        return $this->kvs->save((string) $ro->uri, [$ro->uri, $ro->code, $ro->headers, $body, null], $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        $data = $this->kvs->fetch((string) $uri);
        if ($data === false) {
            return false;
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(AbstractUri $uri)
    {
        $this->deleteEtagDatabase($uri);

        return $this->kvs->delete((string) $uri);
    }

    /**
     * Delete etag in etag repository
     *
     * @param AbstractUri $uri
     */
    public function deleteEtagDatabase(AbstractUri $uri)
    {
        $etagId = self::ETAG_BY_URI . (string) $uri; // invalidate etag
        $oldEtagKey = $this->kvs->fetch($etagId);

        $this->kvs->delete($oldEtagKey);
    }

    private function evaluateBody($body)
    {
        if (! \is_array($body)) {
            return $body;
        }
        foreach ($body as &$item) {
            if ($item instanceof RequestInterface) {
                $item = ($item)();
            }
        }

        return $body;
    }

    /**
     * @return Cacheable|null
     */
    private function getCacheable(ResourceObject $ro)
    {
        /** @var Cacheable|null $cache */
        $cache = $this->reader->getClassAnnotation(new \ReflectionClass($ro), Cacheable::class);

        return $cache;
    }

    /**
     * Update etag in etag repository
     *
     * @param ResourceObject $ro
     */
    private function updateEtagDatabase(ResourceObject $ro)
    {
        $etag = $ro->headers['ETag'];
        $uri = (string) $ro->uri;
        $etagUri = self::ETAG_BY_URI . $uri;
        $oldEtag = $this->kvs->fetch($etagUri);
        if ($oldEtag) {
            $this->kvs->delete($oldEtag);
        }
        $etagId = HttpCache::ETAG_KEY . $etag;
        $this->kvs->save($etagId, $uri);     // save etag
        $this->kvs->save($etagUri, $etagId); // save uri  mapping etag
    }

    private function getExpiryTime(ResourceObject $ro, Cacheable $cacheable = null) : int
    {
        if ($cacheable === null) {
            return 0;
        }

        if ($cacheable->expiryAt) {
            return $this->getExpiryAtSec($ro, $cacheable);
        }

        return $cacheable->expirySecond ? $cacheable->expirySecond : $this->expiry[$cacheable->expiry];
    }

    private function getExpiryAtSec(ResourceObject $ro, Cacheable $cacheable) : int
    {
        if (! isset($ro->body[$cacheable->expiryAt])) {
            $msg = \sprintf('%s::%s', \get_class($ro), $cacheable->expiryAt);
            throw new ExpireAtKeyNotExists($msg);
        }
        $expiryAt = $ro->body[$cacheable->expiryAt];
        $sec = \strtotime($expiryAt) - \time();

        return $sec;
    }

    private function setMaxAge(ResourceObject $ro, int $age)
    {
        $setMaxAge = \sprintf('max-age=%d', $age);
        if (isset($ro->headers['Cache-Control'])) {
            $ro->headers['Cache-Control'] .= ', ' . $setMaxAge;

            return;
        }
        $ro->headers['Cache-Control'] = $setMaxAge;
    }
}
