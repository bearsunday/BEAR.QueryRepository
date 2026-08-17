<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Log\Context\CacheErrorContext;
use BEAR\QueryRepository\Log\Context\CacheHitContext;
use BEAR\QueryRepository\Log\Context\CacheMissContext;
use BEAR\QueryRepository\Log\Context\ConditionalRequestContext;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;
use Throwable;

use function assert;
use function is_string;
use function parse_str;
use function sprintf;
use function str_replace;
use function strtoupper;

use const PHP_EOL;

final readonly class CliHttpCache implements HttpCacheInterface
{
    public function __construct(
        private ResourceStorageInterface $storage,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * The answer is recorded as its own conditional_request scope, exactly as the
     * HTTP-facing HttpCache records it: a hit is the 304 decision. No validator
     * presents nothing, so nothing is recorded.
     */
    #[Override]
    public function isNotModified(array $server): bool
    {
        $etag = $this->getEtag($server);
        if ($etag === null) {
            return false;
        }

        $openId = $this->logger->open(new ConditionalRequestContext($etag));
        try {
            $hit = $this->storage->hasEtag($etag);
        } catch (Throwable $e) {
            // Same shape as the HTTP-facing HttpCache: record the outage, close the scope
            // as the established idiom reads it, keep the exception's pre-existing path.
            $this->logger->event(new CacheErrorContext($this->requestUri($server), 'read', $e->getMessage(), $e::class));
            $this->logger->close(new CacheMissContext('etag'), $openId);

            throw $e;
        }

        $this->logger->close($hit ? new CacheHitContext('etag') : new CacheMissContext('etag'), $openId);

        return $hit;
    }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[Override]
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

    /**
     * The request URI when the SAPI array carries one
     *
     * The interface shape names only the validator key, but the real $_SERVER carries the
     * whole request; empty means no URI was resolvable at the transfer boundary.
     *
     * @param array<array-key, mixed> $server
     */
    private function requestUri(array $server): string
    {
        /** @var mixed $uri */
        $uri = $server['REQUEST_URI'] ?? '';

        return is_string($uri) ? $uri : '';
    }
}
