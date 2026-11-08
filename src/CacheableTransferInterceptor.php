<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\RepositoryModule\Annotation\Cacheable;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;
use ReflectionClass;

use function assert;
use function sprintf;
use function str_contains;
use function strtolower;

/**
 * Gives a #[Cacheable] response its client Cache-Control when it is transferred: max-age for a
 * declared lifetime, no-cache for an entry that lives until a write invalidates it
 *
 * @internal
 */
final readonly class CacheableTransferInterceptor implements MethodInterceptor
{
    public function __construct(
        private Expiry $expiry,
        private DeclaredCacheControl $declaredCacheControl = new DeclaredCacheControl(),
    ) {
    }

    /**
     * {@inheritDoc}
     */
    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        $attributes = (new ReflectionClass($ro))->getAttributes(Cacheable::class);
        if ($ro->code === 200 && isset($attributes[0]) && $this->declaredCacheControl->reason($ro) === null) {
            $this->addDirective($ro, $attributes[0]->newInstance());
        }

        return $invocation->proceed();
    }

    private function addDirective(ResourceObject $ro, Cacheable $cacheable): void
    {
        $expiry = new CacheableExpiry($this->expiry);
        $directive = $expiry->isUntilInvalidated($cacheable) ? 'no-cache' : sprintf('max-age=%d', $expiry->seconds($ro, $cacheable));
        $existingName = Header::findKey($ro->headers, Header::CACHE_CONTROL);
        if ($existingName === null) {
            $ro->headers[Header::CACHE_CONTROL] = $directive;

            return;
        }

        // A lifetime or a refusal already there - declared by #[HttpCache] or set by the resource - stands
        foreach ($ro->headers as $name => $value) {
            if (strtolower($name) !== strtolower(Header::CACHE_CONTROL)) {
                continue;
            }

            $lower = strtolower($value);
            if (str_contains($lower, 'max-age') || str_contains($lower, 'no-store') || str_contains($lower, 'no-cache')) {
                return;
            }
        }

        $ro->headers[$existingName] .= ', ' . $directive;
    }
}
