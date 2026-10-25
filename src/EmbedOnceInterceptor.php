<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\Annotation\Embed;
use BEAR\Resource\Request;
use BEAR\Resource\ResourceObject;
use Override;
use Ray\Aop\MethodInterceptor;
use Ray\Aop\MethodInvocation;

use function array_key_exists;
use function assert;
use function is_array;
use function sprintf;
use function trigger_error;

use const E_USER_WARNING;

/**
 * Swaps every embedded `Request` in a donut page's body for a once-only `OnceRequest`
 *
 * Bound (see `DonutCacheModule`) on the same `onGet()` methods `DonutCacheInterceptor`/
 * `DonutCacheableResponseInterceptor` wrap, but with a plain `bindInterceptor()` rather than
 * `bindPriorityInterceptor()`: a priority interceptor always runs outside `EmbedInterceptor`
 * (`#[Embed]`'s own binding is never a priority one), too early to see the `Request`s it
 * places into `$ro->body` - this one needs to run after. Ray.Aop's own onion ordering gives
 * that for free rather than by binding order: `EmbedInterceptor`'s method matcher is a bare
 * `annotatedWith(Embed::class)`, which `Ray\Aop\MethodMatch::onionOrderMatch()` binds
 * synchronously while walking the method's attributes in declared order - strictly before it
 * returns the leftover (including every matcher built with `logicalAnd()`/`logicalOr()`, this
 * one's method matcher among them) to be bound in whatever order they were registered. A
 * method carrying `#[Embed(...)]` therefore always gets `EmbedInterceptor` ahead of this one in
 * its interceptor chain, regardless of whether `DonutCacheModule` or `EmbedResourceModule` is
 * installed first - verified for both orders in `EmbedOnceInterceptorInstallOrderTest`.
 *
 * The `declaredEmbedRels()` check is the fallback for everything that guarantee does not
 * cover - a third-party AOP container with different ordering rules, or a future Ray.Aop
 * release that changes them: if a declared `#[Embed]`'s `rel` is still missing from the body,
 * swapping would either skip it (leaving the double-execution/#226 gap open for that embed)
 * or, if run before `EmbedInterceptor` instead of after, race it. Either way nothing here can
 * safely proceed, so it warns and leaves the body exactly as it found it - `onGet()` and the
 * eventual donut render still work, just without once-only semantics for that one request.
 */
final class EmbedOnceInterceptor implements MethodInterceptor
{
    public function __construct(
        private readonly DonutChildExecutionsInterface $childExecutions,
    ) {
    }

    #[Override]
    public function invoke(MethodInvocation $invocation)
    {
        $ro = $invocation->getThis();
        assert($ro instanceof ResourceObject);
        $this->swap($ro, $invocation);

        return $invocation->proceed();
    }

    /** @param MethodInvocation<object> $invocation */
    private function swap(ResourceObject $ro, MethodInvocation $invocation): void
    {
        $body = $ro->body;
        foreach ($this->declaredEmbedRels($invocation) as $rel) {
            if (is_array($body) && array_key_exists($rel, $body)) {
                continue;
            }

            // The real too-early state: EmbedInterceptor::prepareBody() is what turns a
            // fresh ResourceObject's null body into [] - if this runs first, $body is
            // still null here, never an array missing just one key.
            trigger_error(sprintf(
                '%s: #[Embed(rel: "%s")] on %s::onGet() was not in the body yet - ' .
                'EmbedOnceInterceptor ran before EmbedInterceptor (check module install order); skipping the swap for this request',
                self::class,
                $rel,
                $ro::class,
            ), E_USER_WARNING);

            return;
        }

        if (! is_array($body)) {
            return;
        }

        /** @psalm-suppress MixedAssignment $child */
        foreach ($body as $rel => $child) {
            if (! $child instanceof Request) {
                continue;
            }

            $once = new OnceRequest($child);
            $body[$rel] = $once;
            $this->childExecutions->register($ro, $once);
        }

        $ro->body = $body;
    }

    /**
     * The `#[Embed]` relations declared on the invoked method, `_self` excluded - a self-link
     * embed (`EmbedInterceptor::linkSelf()`) never reaches `$ro->body`, so its absence there
     * is not a sign anything ran out of order.
     *
     * @param MethodInvocation<object> $invocation
     *
     * @return list<string>
     */
    private function declaredEmbedRels(MethodInvocation $invocation): array
    {
        $rels = [];
        foreach ($invocation->getMethod()->getAnnotations() as $annotation) {
            if (! $annotation instanceof Embed || $annotation->rel === '_self') {
                continue;
            }

            $rels[] = $annotation->rel;
        }

        return $rels;
    }
}
