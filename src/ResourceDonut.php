<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceInterface;
use BEAR\Resource\ResourceObject;

use function array_key_exists;
use function assert;
use function explode;
use function hash;
use function is_iterable;
use function max;
use function preg_replace_callback;
use function strtotime;
use function time;

/**
 * Donut cache resource state
 */
final class ResourceDonut
{
    public const FOMRAT = '[le:%s]';

    private const URI_REGEX = '/\[le:(.+)]/';

    /**
     * @param array<string, string> $headers
     * @param int|null              $lastModified Time when the composed content last actually changed
     * @param list<string>|null     $storageTags  Original invalidation tags for the donut template entry
     * @param int|null              $code         Status code the page was stored with; null in entries saved before this field existed
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
    ) {
    }

    public function refresh(ResourceInterface $resource, ResourceObject $ro): ResourceObject
    {
        $etags = new SurrogateKeys($ro->uri);
        $refreshView =  preg_replace_callback(self::URI_REGEX, static function (array $matches) use ($resource, $etags): string {
            $uri = $matches[1];
            $ro = $resource->get($uri);
            $ro->toString();
            if (array_key_exists(Header::SURROGATE_KEY, $ro->headers)) {
                $etags->addTag($ro);
            }

            return (string) $ro->view;
        }, $this->template);

        $ro->headers = $this->headers;
        $ro->view = $refreshView;
        // isset() also guards entries serialized before this property existed
        if (isset($this->code)) {
            $ro->code = $this->code;
        }

        $etags->setSurrogateHeader($ro);

        return $ro;
    }

    public function render(ResourceObject $ro, DonutRendererInterface $storage): ResourceObject
    {
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

    public static function create(ResourceObject $ro, DonutRendererInterface $storage, SurrogateKeys $etags, int|null $ttl, bool $isCacheble): self
    {
        // A resource with nothing to return leaves its body null - a 204 typically does, while
        // an #[Embed] would have made it an array - and a null body holds no embedded request
        // to wrap. It is composed as an empty one rather than refused.
        assert(is_iterable($ro->body) || $ro->body === null);
        if (is_iterable($ro->body)) {
            /** @var mixed $maybeRequest */
            foreach ($ro->body as &$maybeRequest) {
                if ($maybeRequest instanceof AbstractRequest) {
                    $maybeRequest = new DonutRequest($maybeRequest, $storage, $etags);
                }
            }

            unset($maybeRequest);
        }

        $donutTemplate = (string) $ro;

        return new self($donutTemplate, $ro->headers, $ttl, $isCacheble, code: $ro->code);
    }
}
