<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\QueryRepository\Cdn\AkamaiCacheControlHeaderSetter;
use BEAR\QueryRepository\Cdn\FastlyCacheControlHeaderSetter;
use BEAR\RepositoryModule\Annotation\HttpCache;
use BEAR\Resource\AbstractRequest;
use BEAR\Resource\ResourceObject;
use LogicException;
use Throwable;

use function array_keys;
use function array_map;
use function explode;
use function implode;
use function in_array;
use function is_a;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

/** @internal */
final readonly class HeaderSetter
{
    /**
     * The CDN-facing headers unset on an unstorable response, across every flavor (default,
     * Fastly, Akamai) - unset unconditionally, since this package never hands a shared cache
     * a lifetime for a response it is itself refusing to store.
     */
    private const CDN_FACING_HEADERS = [
        Header::CDN_CACHE_CONTROL,
        FastlyCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        AkamaiCacheControlHeaderSetter::CDN_CACHE_CONTROL_HEADER,
        Header::SURROGATE_KEY,
        AkamaiCacheControlHeaderSetter::PURGE_KEYS,
    ];

    /** serialize()'s refusal of a value, which DOM classes follow with a clause; capture group: the class */
    private const SERIALIZATION_REFUSAL = "/^Serialization of '([^']+)' is not allowed\\b/";

    public function __construct(
        private EtagSetterInterface $etagSetter,
        private DeclaredCacheControl $declaredCacheControl = new DeclaredCacheControl(),
        private BodySerializationErrorHandlerInterface $bodySerializationErrorHandler = new ThrowSerializationError(),
    ) {
    }

    /**
     * Set the validator and Cache-Control max-age, strip every CDN-facing header when the
     * response's own Cache-Control already reads private or no-store outside what
     * #[HttpCache]/#[NoHttpCache] declares, or hand an unserializable body to the configured
     * handler
     *
     * The developer-set private/no-store Cache-Control header itself is left as-is; only the
     * CDN-facing headers are stripped. A value #[HttpCache] or #[NoHttpCache] declared is
     * exempt from this check.
     *
     * @return string|null the class serialize() refused, when the handler returned instead of
     *     throwing (the response goes out uncached); null otherwise
     */
    public function __invoke(ResourceObject $ro, int|null $cacheControlMaxAge, HttpCache|null $httpCache, int|null $time = null): string|null
    {
        if ($this->declaredCacheControl->reason($ro) !== null) {
            $this->stripHeaders($ro, self::CDN_FACING_HEADERS);

            return null;
        }

        try {
            ($this->etagSetter)($ro, $time, $httpCache);
        } catch (LogicException $e) {
            // A request's own __serialize() refusal; any other LogicException keeps propagating
            if (! $this->isRequestRefusal($e)) {
                throw $e;
            }

            return $this->handOver($ro, $e, AbstractRequest::class);
        } catch (Throwable $e) {
            // serialize()'s own refusal is this class with this wording; any other Exception
            // (__serialize()/__sleep(), an embedded onGet()) keeps propagating.
            if ($e::class !== 'Exception' || preg_match(self::SERIALIZATION_REFUSAL, $e->getMessage(), $matches) !== 1) {
                throw $e;
            }

            return $this->handOver($ro, $e, $matches[1]);
        }

        if ($cacheControlMaxAge !== null && $cacheControlMaxAge > 0) {
            $this->setCacheControlMaxAge($ro, $cacheControlMaxAge);
        }

        return null;
    }

    /** Did a request's own __serialize() throw $e? */
    private function isRequestRefusal(LogicException $e): bool
    {
        $frame = $e->getTrace()[0] ?? [];

        return $e::class === LogicException::class
            && ($frame['function'] ?? null) === '__serialize'
            && is_a($frame['class'] ?? '', AbstractRequest::class, true);
    }

    /** Let the handler decide, then send the response out uncached; returns the refused class */
    private function handOver(ResourceObject $ro, Throwable $e, string $refused): string
    {
        ($this->bodySerializationErrorHandler)($ro, $e);
        $this->stripHeaders($ro, self::CDN_FACING_HEADERS);
        $this->makeCacheControlPrivate($ro);

        return $refused;
    }

    /** @param list<string> $canonicalNames */
    private function stripHeaders(ResourceObject $ro, array $canonicalNames): void
    {
        foreach ($canonicalNames as $canonical) {
            foreach (array_keys($ro->headers) as $name) {
                if (strtolower($name) === strtolower($canonical)) {
                    unset($ro->headers[$name]);
                }
            }
        }
    }

    private function setCacheControlMaxAge(ResourceObject $ro, int $age): void
    {
        $setMaxAge = sprintf('max-age=%d', $age);
        $existingName = Header::findKey($ro->headers, Header::CACHE_CONTROL);
        if ($existingName === null) {
            $ro->headers[Header::CACHE_CONTROL] = $setMaxAge;

            return;
        }

        // no-store forbids keeping the response at all, so a lifetime beside it is contradictory;
        // every case variant of the key is a Cache-Control the client receives
        foreach ($ro->headers as $name => $value) {
            if (strtolower($name) !== strtolower(Header::CACHE_CONTROL)) {
                continue;
            }

            $lower = strtolower($value);
            if (str_contains($lower, 'max-age') || str_contains($lower, 'no-store')) {
                return;
            }
        }

        $ro->headers[$existingName] .= ', ' . $setMaxAge;
    }

    /** Drop s-maxage and public, add private and keep the other directives, in every case variant of the header */
    private function makeCacheControlPrivate(ResourceObject $ro): void
    {
        [$names, $directives] = $this->cacheControlDirectives($ro);
        $value = implode(', ', $this->withoutSharedCacheDirectives($directives));
        foreach ($names === [] ? [Header::CACHE_CONTROL] : $names as $name) {
            $ro->headers[$name] = $value;
        }
    }

    /**
     * Every Cache-Control key present, case variant by case variant, and every directive
     * across all of them
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function cacheControlDirectives(ResourceObject $ro): array
    {
        $names = [];
        $directives = [];
        foreach (array_keys($ro->headers) as $name) {
            if (strtolower($name) !== strtolower(Header::CACHE_CONTROL)) {
                continue;
            }

            $names[] = $name;
            foreach (explode(',', $ro->headers[$name]) as $directive) {
                $directives[] = trim($directive);
            }
        }

        return [$names, $directives];
    }

    /**
     * @param list<string> $directives
     *
     * @return list<string>
     */
    private function withoutSharedCacheDirectives(array $directives): array
    {
        $kept = [];
        foreach ($directives as $directive) {
            $lower = strtolower($directive);
            if ($lower === '' || $lower === 'public' || $lower === 's-maxage' || str_starts_with($lower, 's-maxage=')) {
                continue;
            }

            $kept[] = $directive;
        }

        if (! in_array('private', array_map('strtolower', $kept), true)) {
            $kept[] = 'private';
        }

        return $kept;
    }
}
