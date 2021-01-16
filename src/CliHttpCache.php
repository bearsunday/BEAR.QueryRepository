<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;

use function assert;
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
     *
     * @var array{HTTP_IF_NONE_MATCH: string}|array{argc: int, argv: array<int, string>} $server
     */
    public function isNotModified(array $server): bool
    {
        if (isset($server['argc']) && $server['argc'] === 4) { // phpcs:ignore /* @phpstan-ignore-line */
            $server = $this->setRequestHeaders($server, $server['argv'][3]);
        }

        return isset($server['HTTP_IF_NONE_MATCH']) && is_string($server['HTTP_IF_NONE_MATCH']) && $this->storage->hasEtag($server['HTTP_IF_NONE_MATCH']);
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
     * @param array<string, string> $server
     *
     * @return array<string, string>
     */
    private function setRequestHeaders(array $server, string $query): array
    {
        parse_str($query, $headers);
        foreach ($headers as $key => $header) {
            $server[$this->getServerKey($key)] = (string) $header;
        }

        return $server;
    }

    private function getServerKey(string $key): string
    {
        return sprintf('HTTP_%s', strtoupper(str_replace('-', '_', $key)));
    }
}
