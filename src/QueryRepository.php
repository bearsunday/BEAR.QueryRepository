<?php
/**
 * This file is part of the BEAR.QueryRepository package.
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\AbstractUri;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Doctrine\Common\Annotations\Reader;

class QueryRepository implements QueryRepositoryInterface
{
    /**
     * @var ResourceStorageInterface
     */
    private $storage;

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
        ResourceStorageInterface $storage,
        Reader $reader,
        Expiry $expiry
    ) {
        $this->setEtag = $setEtag;
        $this->reader = $reader;
        $this->storage = $storage;
        $this->expiry = $expiry;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \ReflectionException
     */
    public function put(ResourceObject $ro)
    {
        $ro->toString();
        ($this->setEtag)($ro);
        if (isset($ro->headers['ETag'])) {
            $this->storage->updateEtag($ro);
        }
        /* @var $cacheable Cacheable */
        $cacheable = $this->getCacheable($ro);
        $lifeTime = $this->getExpiryTime($cacheable);
        $body = $this->evaluateBody($ro->body);
        if ($cacheable instanceof Cacheable && $cacheable->type === 'view') {
            if (! $ro->view) {
                // render
                $ro->view = $ro->toString();
            }

            return $this->storage->saveView($ro, $lifeTime);
        }
        // "value" cache type
        return $this->storage->saveValue($ro, $lifeTime);
    }

    /**
     * {@inheritdoc}
     */
    public function get(AbstractUri $uri)
    {
        return $this->storage->get($uri);
    }

    /**
     * {@inheritdoc}
     */
    public function purge(AbstractUri $uri)
    {
        return $this->storage->delete($uri);
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
     * @throws \ReflectionException
     *
     * @return Cacheable|null
     */
    private function getCacheable(ResourceObject $ro)
    {
        /** @var Cacheable|null $cache */
        $cache = $this->reader->getClassAnnotation(new \ReflectionClass($ro), Cacheable::class);

        return $cache;
    }

    /**
     * @param Cacheable $cacheable
     *
     * @return int
     */
    private function getExpiryTime(Cacheable $cacheable = null)
    {
        if ($cacheable === null) {
            return 0;
        }

        return $cacheable->expirySecond ? $cacheable->expirySecond : $this->expiry[$cacheable->expiry];
    }
}
