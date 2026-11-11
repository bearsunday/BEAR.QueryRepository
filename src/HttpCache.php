<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\HttpCacheInterface as DeprecatedHttpCacheInterface;
use BEAR\RepositoryModule\Annotation\CacheLog;
use BEAR\Resource\AbstractUri;
use BEAR\Sunday\Extension\Transfer\HttpCacheInterface;
use Koriym\SemanticLogger\NullSemanticLogger;
use Koriym\SemanticLogger\SemanticLoggerInterface;
use Override;

use function header;
use function header_remove;
use function http_response_code;

/** @psalm-suppress DeprecatedInterface for BC */
final readonly class HttpCache implements HttpCacheInterface, DeprecatedHttpCacheInterface, UriScopedHttpCacheInterface
{
    private ConditionalRequest $conditionalRequest;

    public function __construct(
        private ResourceStorageInterface $storage,
        #[CacheLog]
        private SemanticLoggerInterface $logger = new NullSemanticLogger(),
        ConditionalRequest|null $conditionalRequest = null,
    ) {
        $this->conditionalRequest = $conditionalRequest ?? new ConditionalRequest($storage, $logger);
    }

    /**
     * {@inheritDoc}
     *
     * Recorded as its own conditional_request scope: a hit is the 304 decision that no get
     * scope ever shows. No If-None-Match presents nothing, so nothing is recorded.
     */
    #[Override]
    public function isNotModified(array $server): bool
    {
        if (! isset($server[Header::HTTP_IF_NONE_MATCH])) {
            return false;
        }

        $ifNoneMatch = $server[Header::HTTP_IF_NONE_MATCH];

        return $this->conditionalRequest->isNotModified($ifNoneMatch, $server);
    }

    /**
     * {@inheritDoc}
     *
     * A validator issued for another URI is not this representation's, so the request is
     * answered in full.
     */
    #[Override]
    public function isNotModifiedFor(AbstractUri $uri, array $server): bool
    {
        if (! isset($server[Header::HTTP_IF_NONE_MATCH])) {
            return false;
        }

        $ifNoneMatch = $server[Header::HTTP_IF_NONE_MATCH];

        return $this->conditionalRequest->isNotModifiedFor($uri, $ifNoneMatch, $server);
    }

    /**
     * {@inheritDoc}
     *
     * @return void
     */
    #[Override]
    public function transfer()
    {
        // Not covered: under CLI there is no response to set. NotModifiedResponseTest reads what
        // a web server sends.
        // @codeCoverageIgnoreStart
        foreach ($this->conditionalRequest->notModifiedHeaders() as $label => $value) {
            header("{$label}: {$value}", false);
        }

        // A cache copies the headers of a 304 onto the response it stored, so PHP's default
        // Content-Type must stay out of it. Setting one and removing it turns the default off.
        header('Content-Type: text/plain');
        header_remove('Content-Type');
        http_response_code(304);
        // @codeCoverageIgnoreEnd
    }
}
