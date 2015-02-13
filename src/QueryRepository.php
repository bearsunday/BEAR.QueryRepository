<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\ResourceObject;
use BEAR\Resource\Uri;
use Doctrine\Common\Cache\Cache;

class QueryRepository implements QueryRepositoryInterface
{
    /**
     * @var Cache
     */
    private $kvs;

    /**
     * @param Cache $kvs
     *
     * @Storage
     */
    public function __construct(Cache $kvs)
    {
        $this->kvs = $kvs;
    }



    /**
     * {@inheritdoc}
     */
    public function put(ResourceObject $ro)
    {
        $data = [$ro->code, $ro->headers, $ro->body, $ro->view];

        return $this->kvs->save((string) $ro->uri, $data);
    }

    /**
     * {@inheritdoc}
     */
    public function get(Uri $uri)
    {
        return $this->kvs->fetch((string) $uri);
    }

    /**
     * {@inheritdoc}
     */
    public function purge(Uri $uri)
    {
        return $this->kvs->delete((string) $uri);
    }
}
