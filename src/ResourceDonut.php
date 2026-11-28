<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Throwable;

use function assert;
use function hash;
use function is_array;
use function is_iterable;
use function max;
use function preg_replace_callback;
use function rawurldecode;
use function rawurlencode;
use function serialize;
use function sprintf;
use function strtotime;
use function time;

/**
 * Donut cache resource state
 *
 * @internal
 */
final class ResourceDonut
{
    // The reference is rawurlencode()'d: percent-encoding leaves no '&', '<', '>', '"' or '\'' for an HTML renderer's
    // autoescaping to change, so the placeholder reads the same echoed raw or through Twig's `{{ hole }}`
    private const FORMAT = '[le2:%s]';

    private const URI_REGEX = '/\[le2:([^\]]+)]/';

    /**
     * Build the placeholder DonutRequest::__toString() embeds and DonutRenderer matches
     */
    public static function placeholder(string $reference): string
    {
        return sprintf(self::FORMAT, rawurlencode($reference));
    }

    /**
     * @param array<string, string>        $headers
     * @param int|null                     $sMaxAge      CDN lifetime and stored lifetime of the page view; unused by a template-only donut
     * @param int|null                     $lastModified Time when the composed content last actually changed
     * @param list<string>                 $storageTags  Invalidation tags of the donut template entry, without the application key the storage adds
     * @param int                          $code         Status code the page was stored with
     * @param array<array-key, mixed>|null $body         The body the page returned, each embedded request null, when its renderer prints no placeholder; null when the template holds the page
     * @param array<array-key, string>     $embeds       toUri() of each request embedded in the body, by its key
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        private readonly string $template,
        private readonly array $headers,
        public readonly int|null $sMaxAge,
        public readonly bool $isPage,
        private readonly int|null $lastModified = null,
        private readonly string|null $contentHash = null,
        private readonly int|null $templateExpiresAt = null,
        private readonly array $storageTags = [],
        private readonly int $code = 200,
        private readonly array|null $body = null,
        private readonly array $embeds = [],
    ) {
    }

    /** Answers the page again from what was stored. Whatever fails on the way degrades the hit to a fresh run of the page. */
    public function refresh(ResourceInterface $resource, ResourceObject $ro, Dependencies $etags): ResourceObject
    {
        try {
            if ($this->body === null) {
                $view = $this->compose($resource, $etags);
                $ro->headers = $this->headers;
                $ro->view = $view;
            }

            if ($this->body !== null) {
                $this->renderBody($this->body, $resource, $ro, $etags);
            }

            $ro->code = $this->code;

            // $this->headers lacks the group tag; add the stored tags.
            (new TagSet($this->getStorageTags()))->addTo($ro);

            $etags->addTo($ro);
        } catch (Throwable $e) {
            throw new CacheStoreException(previous: $e);
        }

        return $ro;
    }

    /** The template with the view of each embedded request, read again, in its place */
    private function compose(ResourceInterface $resource, Dependencies $etags): string|null
    {
        return preg_replace_callback(self::URI_REGEX, static function (array $matches) use ($resource, $etags): string {
            $reference = rawurldecode($matches[1]);
            // toString(), not (string): the cast turns a render failure into '', which would be served
            $ro = $resource->get($reference);
            $ro->toString();
            $etags->hole($ro);

            return (string) $ro->view;
        }, $this->template);
    }

    /**
     * Renders the stored body with the page's own renderer, its embedded requests put back
     *
     * @param array<array-key, mixed> $body
     */
    private function renderBody(array $body, ResourceInterface $resource, ResourceObject $ro, Dependencies $etags): void
    {
        $requests = [];
        foreach ($this->embeds as $key => $reference) {
            $request = $resource->uri($reference);
            assert($request instanceof AbstractRequest);
            $requests[] = $body[$key] = $request;
        }

        $ro->body = $body;
        $ro->headers = $this->headers;
        $ro->code = $this->code;
        $ro->view = null;
        $ro->toString();
        foreach ($requests as $request) {
            // After rendering: what a request passes up from the requests it embeds itself is known once those have run
            $etags->hole(DonutRequest::resultOf($request));
        }
    }

    public function render(ResourceObject $ro, DonutRendererInterface $storage): ResourceObject
    {
        // A page stored as its body was rendered when the donut was created
        if ($this->body !== null) {
            return $ro;
        }

        $view = $storage->render($this->template);
        $ro->view = $view;

        return $ro;
    }

    /**
     * Return the Last-Modified time to carry over when the refreshed content is
     * byte-identical to the content recorded with this donut, null otherwise.
     *
     * RFC 9110 §8.8.2 defines Last-Modified as the time the representation was
     * last changed, so a recomposition that yields identical content must keep
     * the original value instead of advancing it to the recomposition time.
     */
    public function getUnchangedLastModified(string $view): int|null
    {
        if ($this->contentHash === null || $this->lastModified === null) {
            return null;
        }

        return $this->contentHash === $this->hashContent($view) ? $this->lastModified : null;
    }

    /**
     * Return a copy that records the content hash and the Last-Modified time of the given RO
     */
    public function withContentState(ResourceObject $ro): self
    {
        $lastModified = null;
        if (isset($ro->headers[Header::LAST_MODIFIED])) {
            $time = strtotime($ro->headers[Header::LAST_MODIFIED]);
            $lastModified = $time === false ? null : $time;
        }

        return new self(
            $this->template,
            $this->headers,
            $this->sMaxAge,
            $this->isPage,
            $lastModified,
            $this->hashContent((string) $ro->view),
            $this->templateExpiresAt,
            $this->storageTags,
            $this->code,
            $this->body,
            $this->embeds,
        );
    }

    /**
     * Return a copy that records the original storage lifetime and invalidation tags
     *
     * @param list<string> $tags
     */
    public function withStorageState(int|null $ttl, array $tags): self
    {
        return new self(
            $this->template,
            $this->headers,
            $this->sMaxAge,
            $this->isPage,
            $this->lastModified,
            $this->contentHash,
            $ttl !== null && $ttl > 0 ? time() + $ttl : null,
            $tags,
            $this->code,
            $this->body,
            $this->embeds,
        );
    }

    /**
     * Return the remaining explicit template TTL, null when no explicit TTL was set
     */
    public function getRemainingStorageTtl(): int|null
    {
        return $this->templateExpiresAt === null ? null : max(0, $this->templateExpiresAt - time());
    }

    /** @return list<string> */
    public function getStorageTags(): array
    {
        return $this->storageTags;
    }

    private function hashContent(string $view): string
    {
        return hash('sha256', $view);
    }

    /**
     * Renders via `toString()`, not `(string) $ro`, which swallows a render failure into
     * an `E_USER_WARNING` and `''` - that would store an empty template for every later visitor.
     */
    public static function create(ResourceObject $ro, DonutRendererInterface $storage, Dependencies $etags, int|null $sMaxAge, bool $isPage): self
    {
        // A resource with nothing to return leaves its body null - a 204 typically does, while
        // an #[Embed] would have made it an array - and a null body holds no embedded request
        // to wrap. It is composed as an empty one rather than refused.
        assert(is_iterable($ro->body) || $ro->body === null);
        $embeds = self::wrapEmbeds($ro, $storage, $etags);
        try {
            $donutTemplate = $ro->toString();
        } catch (Throwable $e) {
            self::restoreEmbeds($ro, $embeds);

            throw $e;
        }

        foreach ($embeds as $embed) {
            if ($embed->isEncoded && is_array($ro->body)) {
                return self::ofBody($ro, $embeds, $etags, $sMaxAge, $isPage);
            }
        }

        return new self($donutTemplate, $ro->headers, $sMaxAge, $isPage, code: $ro->code);
    }

    /** @return array<array-key, DonutRequest> */
    private static function wrapEmbeds(ResourceObject $ro, DonutRendererInterface $storage, Dependencies $etags): array
    {
        if (! is_iterable($ro->body)) {
            return [];
        }

        $embeds = [];
        /**
         * @var array-key $key
         * @var mixed     $maybeRequest
         */
        foreach ($ro->body as $key => &$maybeRequest) {
            if ($maybeRequest instanceof DonutRequest) {
                $maybeRequest = $maybeRequest->request;
            }

            if ($maybeRequest instanceof AbstractRequest) {
                $maybeRequest = $embeds[$key] = new DonutRequest($maybeRequest, $storage, $etags);
            }
        }

        unset($maybeRequest);

        return $embeds;
    }

    /** @param array<array-key, DonutRequest> $embeds */
    private static function restoreEmbeds(ResourceObject $ro, array $embeds): void
    {
        if (! is_array($ro->body)) {
            return;
        }

        foreach ($embeds as $key => $embed) {
            $ro->body[$key] = $embed->request;
        }
    }

    /**
     * A renderer that encodes an embedded request as JSON prints no placeholder for it. The page is stored as the body
     * it returned and rendered again on each hit with those requests put back, so it shows what it shows without the donut.
     *
     * @param array<array-key, DonutRequest> $embeds
     */
    private static function ofBody(ResourceObject $ro, array $embeds, Dependencies $etags, int|null $sMaxAge, bool $isPage): self
    {
        assert(is_array($ro->body));
        $body = $ro->body;
        $references = [];
        foreach ($embeds as $key => $embed) {
            $ro->body[$key] = $embed->request;
            $body[$key] = null;
            $references[$key] = $embed->request->toUri();
        }

        // The body is what the store keeps, so it must serialize: a refusal surfaces here, before anything is written or purged
        serialize($body);
        // A request that is not a Request, such as bear/async's AsyncRequest, hands out its resource only when invoked.
        // Invoked before the renderer reads it, it is rendered from that run and not run a second time.
        $invoked = [];
        foreach ($embeds as $key => $embed) {
            if (! $embed->request instanceof Request) {
                $invoked[$key] = ($embed->request)();
            }
        }

        $ro->view = null;
        $ro->toString();
        foreach ($embeds as $key => $embed) {
            // After rendering: what a request passes up from the requests it embeds itself is known once those have run
            $etags->hole($invoked[$key] ?? DonutRequest::resultOf($embed->request));
        }

        return new self('', $ro->headers, $sMaxAge, $isPage, code: $ro->code, body: $body, embeds: $references);
    }
}
