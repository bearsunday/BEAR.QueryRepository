<?php
/**
 * This file is part of the BEAR.QueryRepository package
 *
 * @license http://opensource.org/licenses/MIT MIT
 */
namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Storage;
use BEAR\Resource\ResourceObject;
use BEAR\Sunday\Extension\Transfer\TransferInterface;
use Doctrine\Common\Cache\Cache;

final class HttpCacheSaver implements TransferInterface
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

    public function __invoke(ResourceObject $resourceObject, array $server)
    {
        if (PHP_SAPI === 'cli' || (! isset($resourceObject->headers['Etag'])) || $resourceObject->code !== 200) {
            return;
        }
        $requestUriEtag = 'request-uri-etag:' . $server['REQUEST_URI'] . $resourceObject->headers['Etag'];
        if ($this->kvs->contains($requestUriEtag)) {
            return;
        }
        $requestUri = 'request-uri:' . $server['REQUEST_URI'];
        $this->kvs->delete($this->kvs->fetch($requestUri));
        // request-uri:/foo => 1755138345
        $this->kvs->save($requestUri, $requestUriEtag);
        // request-uri-etag:/1755138345 => [header, contents]
        $this->kvs->save(
            $requestUriEtag,
            [$resourceObject->headers, $resourceObject->view]
        );
    }
}
