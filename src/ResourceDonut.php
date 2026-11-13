<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Exception\CacheStoreException;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;
use Throwable;

use function array_unique;
use function assert;
use function explode;
use function hash;
use function implode;
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
 */
final class ResourceDonut
{
    // `le2` placeholders (written by this version onward) carry a rawurlencode()'d URI:
    // percent-encoding leaves no '&', '<', '>', '"' or '\'' for an HTML renderer's default
    // autoescaping to mangle, so the same placeholder text round-trips unchanged whether
    // it is echoed raw, through Twig's default `{{ hole }}` escaping, or by a non-HTML
    // renderer (issue #232). A legacy `le` placeholder (written before this version) is
    // never decoded - its URI is used exactly as refresh() always used it, which stays
    // correct for 0/1 query params (and still broken for 2+, as it already was: that
    // entry was already corrupted and self-heals once it expires and is rewritten).
    // Decoding it here too would be wrong, not merely redundant: a legacy query VALUE
    // that itself needed percent-encoding (a literal '&', '=', '+', '/', non-ASCII bytes)
    // would be stripped of that encoding before BEAR\Resource\Uri ever parses it, either
    // corrupting the value (parse_str re-splits it on the now-literal '&'/'=') or failing
    // URL validation outright (a raw non-ASCII byte is not a valid URL character).
    private const FORMAT = '[le2:%s]';

    private const URI_REGEX = '/\[le(2)?:([^\]]+)]/';

    /**
     * Build the placeholder DonutRequest::__toString() embeds and DonutRenderer matches
     */
    public static function placeholder(string $reference): string
    {
        return sprintf(self::FORMAT, rawurlencode($reference));
    }

    /** @var array<array-key, mixed>|null The body the page returned, each embedded request null, when its renderer prints no placeholder; null when the template holds the page */
    private array|null $body = null;

    /** @var array<array-key, string> toUri() of each request embedded in the body, by its key */
    private array $embeds = [];

    /**
     * @param array<string, string>        $headers
     * @param int|null                     $lastModified Time when the composed content last actually changed
     * @param list<string>|null            $storageTags  Original invalidation tags for the donut template entry
     * @param int|null                     $code         Status code the page was stored with; null in entries saved before this field existed
     * @param array<array-key, mixed>|null $body
     * @param array<array-key, string>     $embeds
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList")
     */
    public function __construct(
        private readonly string $template,
        private readonly array $headers,
        /** @readonly */
        public int|null $ttl,
        /** @readonly */
        public bool $isCacheble,
        /** @readonly */
        public int|null $lastModified = null,
        private readonly string|null $contentHash = null,
        private readonly int|null $templateExpiresAt = null,
        private readonly array|null $storageTags = null,
        private readonly int|null $code = null,
        array|null $body = null,
        array $embeds = [],
    ) {
        $this->body = $body;
        $this->embeds = $embeds;
    }

    /**
     * Answers the page again from what was stored. Whatever fails on the way degrades the hit to a fresh run of the page.
     *
     * @param DependsOnTags|null $dependsOnTags reads what each hole declares as it is rendered again; null reads its #[DependsOn] only
     */
    public function refresh(ResourceInterface $resource, ResourceObject $ro, UriTagInterface|null $uriTag = null, DependsOnTags|null $dependsOnTags = null): ResourceObject
    {
        try {
            $etags = new SurrogateKeys($ro->uri, null, $uriTag, null, $dependsOnTags);
            if ($this->body === null) {
                $view = $this->compose($resource, $etags);
                $ro->headers = $this->headers;
                $ro->view = $view;
            }

            if ($this->body !== null) {
                $this->renderBody($this->body, $resource, $ro, $etags);
            }

            // isset() also guards entries serialized before this property existed
            if (isset($this->code)) {
                $ro->code = $this->code;
            }

            // $this->headers lacks the group tag; add the stored tags.
            $storedTags = $this->getStorageTags();
            if ($storedTags !== []) {
                $existing = isset($ro->headers[Header::SURROGATE_KEY]) ? explode(' ', $ro->headers[Header::SURROGATE_KEY]) : [];
                $ro->headers[Header::SURROGATE_KEY] = implode(' ', array_unique([...$existing, ...$storedTags]));
            }

            $etags->setSurrogateHeader($ro);
        } catch (Throwable $e) {
            throw new CacheStoreException(previous: $e);
        }

        return $ro;
    }

    /** The template with the view of each embedded request, read again, in its place */
    private function compose(ResourceInterface $resource, SurrogateKeys $etags): string|null
    {
        return preg_replace_callback(self::URI_REGEX, static function (array $matches) use ($resource, $etags): string {
            // Group 1 is "2" for a new `le2` match (its reference, group 2, is rawurlencode()'d),
            // or "" for a legacy `le` match (group 2 is its URI, used verbatim).
            $reference = $matches[1] === '2' ? rawurldecode($matches[2]) : $matches[2];
            // toString(), not (string): the cast turns a render failure into '', which would be served
            $ro = $resource->get($reference);
            $ro->toString();
            $etags->addTag($ro);

            return (string) $ro->view;
        }, $this->template);
    }

    /**
     * Renders the stored body with the page's own renderer, its embedded requests put back
     *
     * @param array<array-key, mixed> $body
     */
    private function renderBody(array $body, ResourceInterface $resource, ResourceObject $ro, SurrogateKeys $etags): void
    {
        $requests = [];
        foreach ($this->embeds as $key => $reference) {
            $request = $resource->uri($reference);
            assert($request instanceof AbstractRequest);
            $requests[] = $body[$key] = $request;
        }

        $ro->body = $body;
        $ro->headers = $this->headers;
        $ro->code = $this->code ?? $ro->code;
        $ro->view = null;
        $ro->toString();
        foreach ($requests as $request) {
            // After rendering: what a request passes up from the requests it embeds itself is known once those have run
            $etags->addTag(DonutRequest::resultOf($request));
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
        // isset() also guards entries serialized before these properties existed
        if (! isset($this->contentHash, $this->lastModified)) {
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

        $templateExpiresAt = $this->templateExpiresAt ?? null;
        $storageTags = $this->storageTags ?? null;

        return new self(
            $this->template,
            $this->headers,
            $this->ttl,
            $this->isCacheble,
            $lastModified,
            $this->hashContent((string) $ro->view),
            $templateExpiresAt,
            $storageTags,
            $this->code ?? null,
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
        $lastModified = $this->lastModified ?? null;
        $contentHash = $this->contentHash ?? null;
        $templateExpiresAt = $ttl !== null && $ttl > 0 ? time() + $ttl : null;

        return new self(
            $this->template,
            $this->headers,
            $this->ttl,
            $this->isCacheble,
            $lastModified,
            $contentHash,
            $templateExpiresAt,
            $tags,
            $this->code ?? null,
            $this->body,
            $this->embeds,
        );
    }

    /**
     * Return the remaining explicit template TTL, null when no explicit TTL was set
     */
    public function getRemainingStorageTtl(): int|null
    {
        if (! isset($this->templateExpiresAt)) {
            return null;
        }

        return max(0, $this->templateExpiresAt - time());
    }

    /** @return list<string> */
    public function getStorageTags(): array
    {
        if (isset($this->storageTags)) {
            return $this->storageTags;
        }

        return isset($this->headers[Header::SURROGATE_KEY]) ? explode(' ', $this->headers[Header::SURROGATE_KEY]) : [];
    }

    private function hashContent(string $view): string
    {
        return hash('sha256', $view);
    }

    /**
     * Renders via `toString()`, not `(string) $ro`, which swallows a render failure into
     * an `E_USER_WARNING` and `''` - that would store an empty template for every later visitor.
     */
    public static function create(ResourceObject $ro, DonutRendererInterface $storage, SurrogateKeys $etags, int|null $ttl, bool $isCacheble): self
    {
        // A resource with nothing to return leaves its body null - a 204 typically does, while
        // an #[Embed] would have made it an array - and a null body holds no embedded request
        // to wrap. It is composed as an empty one rather than refused.
        assert(is_iterable($ro->body) || $ro->body === null);
        $embeds = [];
        if (is_iterable($ro->body)) {
            /**
             * @var array-key $key
             * @var mixed     $maybeRequest
             */
            foreach ($ro->body as $key => &$maybeRequest) {
                if ($maybeRequest instanceof AbstractRequest) {
                    $maybeRequest = $embeds[$key] = new DonutRequest($maybeRequest, $storage, $etags);
                }
            }

            unset($maybeRequest);
        }

        $donutTemplate = $ro->toString();
        foreach ($embeds as $embed) {
            if ($embed->isEncoded && is_array($ro->body)) {
                return self::ofBody($ro, $embeds, $etags, $ttl, $isCacheble);
            }
        }

        return new self($donutTemplate, $ro->headers, $ttl, $isCacheble, code: $ro->code);
    }

    /**
     * A renderer that encodes an embedded request as JSON prints no placeholder for it. The page is stored as the body
     * it returned and rendered again on each hit with those requests put back, so it shows what it shows without the donut.
     *
     * @param array<array-key, DonutRequest> $embeds
     */
    private static function ofBody(ResourceObject $ro, array $embeds, SurrogateKeys $etags, int|null $ttl, bool $isCacheble): self
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
            $etags->addTag($invoked[$key] ?? DonutRequest::resultOf($embed->request));
        }

        return new self('', $ro->headers, $ttl, $isCacheble, code: $ro->code, body: $body, embeds: $references);
    }
}
