<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

final class CliHttpCache implements HttpCacheInterface
{
    /**
     * @var ResourceStorageInterface
     */
    private $storage;

    public function __construct(ResourceStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * {@inheritdoc}
     */
    public function isNotModified(array $server) : bool
    {
        if (isset($server['argc']) && $server['argc'] === 4) {
            $server = $this->setRequestHeaders($server, $server['argv'][3]);
        }

        return isset($server['HTTP_IF_NONE_MATCH']) && $this->storage->hasEtag($server['HTTP_IF_NONE_MATCH']);
    }

    /**
     * {@inheritdoc}
     */
    public function transfer()
    {
        echo '304 Not Modified' . PHP_EOL . PHP_EOL;
    }

    private function setRequestHeaders(array $server, string $query) : array
    {
        \parse_str($query, $headers);
        foreach ($headers as $key => $header) {
            $server[$this->getServerKey($key)] = (string) $header;
        }

        return $server;
    }

    private function getServerKey(string $key)
    {
        return sprintf('HTTP_%s', strtoupper(\str_replace('-', '_', $key)));
    }
}
