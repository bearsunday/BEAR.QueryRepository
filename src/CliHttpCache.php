<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;

use function is_string;
use function parse_str;
use function sprintf;
use function str_replace;
use function strtoupper;

use const PHP_EOL;

final class CliHttpCache implements HttpCacheInterface
{
    /** @var ResourceStorageInterface */
    private $storage;

    public function __construct(ResourceStorageInterface $storage)
    {
        $this->storage = $storage;
    }

    /**
     * {@inheritdoc}
     */
    public function isNotModified(array $server): bool
    {
        $etag = $this->getEtag($server);
        if ($etag === null) {
            return false;
        }

        return $this->storage->hasEtag($etag);
    }

    /**
     * {@inheritdoc}
     *
     * @return void
     */
    public function transfer()
    {
        echo '304 Not Modified' . PHP_EOL . PHP_EOL;
    }

    /**
     * @return array<string, string>
     */
    private function getServer(string $query): array
    {
        parse_str($query, $headers);
        $server = [];
        /** @var string $header */
        foreach ($headers as $key => $header) {
            assert(is_string($key));
            $server[$this->getServerKey($key)] = $header;
        }

        return $server;
    }

    private function getServerKey(string $key): string
    {
        return sprintf('HTTP_%s', strtoupper(str_replace('-', '_', $key)));
    }

    /**
     * @param array<string, mixed> $server
     */
    private function getEtag(array $server): ?string
    {
        $hasRequestHeaderInCli = isset($server['argc']) && $server['argc'] === 4 && isset($server['argv'][3]);
        if ($hasRequestHeaderInCli) {
            $server = $this->getServer((string) $server['argv'][3]);
        }
        assert(is_string($server['HTTP_IF_NONE_MATCH']));

        return $server['HTTP_IF_NONE_MATCH'] ?? null;
    }
}
