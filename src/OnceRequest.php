<?php

declare(strict_types=1);

namespace BEAR\QueryRepository;

use BEAR\Resource\AbstractRequest;
use BEAR\Resource\Request;
use BEAR\Resource\RequestInterface;
use BEAR\Resource\ResourceObject;
use OutOfRangeException;
use Override;

use function count;
use function in_array;

/**
 * Runs the wrapped embedded `Request` at most once per query/links generation
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
 * silently answer the previous one. Each such re-execution is a new *generation* - and
 * `$executions` keeps every one of them, not only the current one: `onGet()` reading a
 * child, re-querying it, and reading it again (never leaving either result as a hole) must
 * tag both generations' own URIs/surrogate keys for `DonutRepository` (issue #226), not just
 * the last - purging the FIRST generation's tag must invalidate the page just as much as
 * purging the second's.
 *
 * `AbstractRequest` itself never gets this treatment and is never asked to: a plain
 * `$resource->get()`/`newRequest()` call in application code, or a `#[Cacheable]` resource
 * read outside a donut page, keeps running every time it is invoked, exactly as before.
 *
 * @psalm-suppress PropertyNotSetInConstructor `$invoker`/`$linker` are AbstractRequest's own
 *     construction-only properties, used only by its default `__invoke()` - overridden here
 *     to always delegate to the inner `Request` instead, so neither is ever read.
 * @psalm-type Execution = array{resourceObject: ResourceObject, rendered: bool}
 */
final class OnceRequest extends AbstractRequest
{
    private ResourceObject|null $executed = null;

    /** @var list<ResourceObject> every generation's result, oldest first */
    private array $generations = [];

    /** @var array<int, bool> parallel to $generations - whether DonutRequest rendered that one as a hole */
    private array $rendered = [];

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
     * The single execution point for the current generation: runs the inner `Request` the
     * first time this is called (directly, or through `AbstractRequest`'s own memo via
     * `__toString()`/`offsetGet()`/etc.) and returns that same result on every later call,
     * until a query or link change starts a new generation.
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
            $this->generations[] = $this->executed;
            $this->rendered[] = false;
        }

        return $this->executed;
    }

    /**
     * {@inheritDoc}
     *
     * Mirrors `Request::__get()`'s own DSL exactly - `AbstractRequest`'s default `__get()`
     * answers through its own private memo, not this class's `$executed`, which would read
     * as unset (and so re-execute) even after a prior `offsetGet()`/`__toString()` call
     * already ran this generation through `__invoke()` above.
     */
    #[Override]
    public function __get(string $name): mixed
    {
        if ($name === 'eager' || $name === 'lazy') {
            $this->in = $name;
            $this->request->in = $name;

            return $this;
        }

        if (in_array($name, ['code', 'headers', 'body'], true)) {
            return ($this)()->{$name};
        }

        throw new OutOfRangeException($name);
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

    /** Whether the current generation's inner `Request` has run yet */
    public function hasExecuted(): bool
    {
        return $this->executed !== null;
    }

    /**
     * Record that the current generation's execution was substituted into the donut
     * template as a hole
     *
     * Called by `DonutRequest::__toString()` once it has rendered this generation - so that
     * `DonutRepository` (issue #226) does not also tag it as a body-only, non-hole read: the
     * hole already carries its own tag (`DonutRequest` adds it directly), and a rendered
     * hole's tag is deliberately kept out of the donut template's own storage tags
     * (`DonutRepository::templateKeys()`) so that the child's own write drops the rendered
     * content and leaves the shell to be recomposed, not the whole template. Only ever the
     * LATEST generation can be a hole - the template at render time holds whatever is
     * currently in `$ro->body[$rel]`, which a superseded earlier generation no longer is.
     *
     * `DonutRequest` only ever calls this right after invoking this request, so there is
     * always at least one generation to mark by the time this runs.
     */
    public function markRendered(): void
    {
        $this->rendered[count($this->rendered) - 1] = true;
    }

    /**
     * Every generation this request has run across every withQuery()/addQuery()/link*()
     * call, oldest first, paired with whether `DonutRequest` rendered it as a hole
     *
     * Read once by `DonutRepository` (issue #226) to tag every generation that executed but
     * was never rendered as a hole - not only the current one: `onGet()` re-querying an
     * embed mid-request (see class docblock) leaves an earlier generation's own URI/
     * surrogate key just as reachable through `purge()` as the latest's.
     *
     * @return list<array{resourceObject: ResourceObject, rendered: bool}>
     */
    public function generations(): array
    {
        $result = [];
        foreach ($this->generations as $i => $resourceObject) {
            $result[] = ['resourceObject' => $resourceObject, 'rendered' => $this->rendered[$i]];
        }

        return $result;
    }

    /** Discard the current generation's memo and mirror the inner `Request`'s query/links after they changed */
    private function resync(): void
    {
        $this->query = $this->request->query;
        $this->links = $this->request->links;
        $this->executed = null;
        // $this->generations/$this->rendered are NOT cleared here - issue #226 needs every
        // generation's own tag, not only the one still current after this change.
        // AbstractRequest's own private invoke() short-circuits on $result !== null,
        // bypassing our overridden __invoke() entirely for every accessor that goes
        // through it (offsetGet(), __get(), getIterator(), __toString(), jsonSerialize()) -
        // left stale here, a withQuery()/addQuery()/link*() call after such a read would
        // keep answering the pre-change ResourceObject forever.
        $this->result = null;
    }
}
