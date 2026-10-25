<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Request;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use Override;

/**
 * Runs the wrapped embedded `Request` at most once
 *
 * `EmbedInterceptor` places a fresh `Request` clone into `$ro->body[$rel]` for every
 * `#[Embed]`. Left as-is, that `Request` can be executed twice for one page render: once
 * when the page's own `onGet()` reads a value out of it (`offsetGet()`/`__get()`, which goes
 * through `AbstractRequest`'s private memo), and once more when `DonutRequest::__toString()`
 * renders it as a hole (`($this->request)()`, which calls `__invoke()` directly and so
 * bypasses that memo - see `DonutRequest`). For a non-idempotent child (a counter, a random
 * value) the two runs disagree, and the donut template ends up holding a different value
 * than whatever the page's own body already used - the MISS render and the stored render
 * diverge.
 *
 * `EmbedOnceInterceptor` swaps every embedded `Request` for one of these before `onGet()`
 * runs, so every access path - `onGet()` reading the body, and the later donut hole render -
 * shares the one execution. `__invoke()` is the only path that actually calls the inner
 * `Request`; every other `AbstractRequest` accessor (`__toString()`, `offsetGet()`, `__get()`,
 * `getIterator()`, `jsonSerialize()`) already funnels through `__invoke()` via
 * `AbstractRequest`'s own private memo, and callers that invoke `($wrapper)()` directly -
 * `DonutRequest` among them - hit this class's own memo instead of the inner `Request`'s,
 * closing exactly the gap described above.
 *
 * `withQuery()`/`addQuery()`/`link*()` discard the memo: a child whose query or links the
 * page explicitly changes mid-`onGet()` is expected to run again under the new request, not
 * silently answer the previous one.
 *
 * `AbstractRequest` itself never gets this treatment and is never asked to: a plain
 * `$resource->get()`/`newRequest()` call in application code, or a `#[Cacheable]` resource
 * read outside a donut page, keeps running every time it is invoked, exactly as before.
 *
 * @psalm-suppress PropertyNotSetInConstructor `$invoker`/`$linker` are AbstractRequest's own
 *     construction-only properties, used only by its default `__invoke()` - overridden here
 *     to always delegate to the inner `Request` instead, so neither is ever read.
 */
final class OnceRequest extends AbstractRequest
{
    private ResourceObject|null $executed = null;
    private bool $rendered = false;

    public function __construct(private readonly Request $request)
    {
        $this->resourceObject = $request->resourceObject;
        $this->uri = $request->uri;
        $this->method = $request->method;
        $this->query = $request->query;
        $this->links = $request->links;
        $this->in = $request->in;
    }

    /**
     * {@inheritDoc}
     *
     * The single execution point: runs the inner `Request` the first time this is called
     * (directly, or through `AbstractRequest`'s own memo via `__toString()`/`offsetGet()`/
     * etc.) and returns that same result on every later call, until a query or link change
     * discards it.
     */
    #[Override]
    public function __invoke(array|null $query = null): ResourceObject
    {
        if ($query !== null) {
            $this->request->addQuery($query);
            $this->resync();
        }

        if ($this->executed === null) {
            $this->executed = ($this->request)();
        }

        return $this->executed;
    }

    /** {@inheritDoc} */
    #[Override]
    public function withQuery(array $query): RequestInterface
    {
        $this->request->withQuery($query);
        $this->resync();

        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function addQuery(array $query): RequestInterface
    {
        $this->request->addQuery($query);
        $this->resync();

        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function toUri(): string
    {
        return $this->request->toUri();
    }

    /** {@inheritDoc} */
    #[Override]
    public function toUriWithMethod(): string
    {
        return $this->request->toUriWithMethod();
    }

    /** {@inheritDoc} */
    #[Override]
    public function linkSelf(string $linkKey): RequestInterface
    {
        $this->request->linkSelf($linkKey);
        $this->resync();

        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function linkNew(string $linkKey): RequestInterface
    {
        $this->request->linkNew($linkKey);
        $this->resync();

        return $this;
    }

    /** {@inheritDoc} */
    #[Override]
    public function linkCrawl(string $linkKey): RequestInterface
    {
        $this->request->linkCrawl($linkKey);
        $this->resync();

        return $this;
    }

    /** Whether the inner `Request` has run, under the query/links currently set */
    public function hasExecuted(): bool
    {
        return $this->executed !== null;
    }

    /** Whether `DonutRequest` has rendered this execution as a hole (see `markRendered()`) */
    public function wasRendered(): bool
    {
        return $this->rendered;
    }

    /**
     * Record that this execution was substituted into the donut template as a hole
     *
     * Called by `DonutRequest::__toString()` once it has rendered this child - so that
     * `DonutRepository` (issue #226) does not also tag it as a body-only, non-hole read:
     * the hole already carries its own tag (`DonutRequest` adds it directly), and a
     * rendered hole's tag is deliberately kept out of the donut template's own storage
     * tags (`DonutRepository::templateKeys()`) so that the child's own write drops the
     * rendered content and leaves the shell to be recomposed, not the whole template.
     */
    public function markRendered(): void
    {
        $this->rendered = true;
    }

    /**
     * Return the single execution's result, running it now if nothing has read it yet
     *
     * Never executes a second time: used by `DonutRepository` (issue #226) to read the
     * tags of a child that already ran, not to decide whether it should run.
     */
    public function executedResourceObject(): ResourceObject
    {
        return ($this)();
    }

    /** Discard the memo and mirror the inner `Request`'s query/links after they changed */
    private function resync(): void
    {
        $this->query = $this->request->query;
        $this->links = $this->request->links;
        $this->executed = null;
        $this->rendered = false;
        // AbstractRequest's own private invoke() short-circuits on $result !== null,
        // bypassing our overridden __invoke() entirely for every accessor that goes
        // through it (offsetGet(), __get(), getIterator(), __toString(), jsonSerialize()) -
        // left stale here, a withQuery()/addQuery()/link*() call after such a read would
        // keep answering the pre-change ResourceObject forever.
        $this->result = null;
    }
}
