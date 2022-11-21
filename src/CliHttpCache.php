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
    public function __construct(
        private ResourceStorageInterface $storage,
    ) {
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

    /** @return array<string, string> */
    private function getServer(string $query): array
    {
        parse_str($query, $headers);
        $server = [];
        foreach ($headers as $key => $header) {
            assert(is_string($header));
            assert(is_string($key));
            $server[$this->getServerKey($key)] = $header;
        }

        return $server;
    }

    private function getServerKey(string $key): string
    {
        return sprintf('HTTP_%s', strtoupper(str_replace('-', '_', $key)));
    }

    /** @param array<string, mixed> $server */
    private function getEtag(array $server): string|null
    {
        /** @psalm-suppress MixedAssignment */
        $arg3 = $server['argv'][3] ?? ''; /* @phpstan-ignore-line */
        assert(is_string($arg3));
        $hasRequestHeaderInCli = isset($server['argc']) && $server['argc'] === 4 && $arg3;
        if ($hasRequestHeaderInCli) {
            /** @psalm-suppress MixedArrayAccess */
            $server = $this->getServer($arg3);
        }

        if (isset($server[Header::HTTP_IF_NONE_MATCH]) && is_string($server[Header::HTTP_IF_NONE_MATCH])) {
            return $server[Header::HTTP_IF_NONE_MATCH];
        }

        return null;
    }
}
