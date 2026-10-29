# The declared cache graph (`qr-graph`)

Cache behavior in BEAR.QueryRepository is declared: `#[Embed]` says what a resource depends on,
`#[Refresh]`/`#[Purge]` say what a write invalidates, and `#[Cacheable]`/`#[CacheableResponse]`/`#[DonutCache]`
say what is cached at all. `qr-graph` reads those declarations, without running the application, and
prints the graph they form.

The semantic log records what the cache *did*, as instance URIs (`depends_on`, `invalidate`, ...).
This graph records what the code *declares*, as resource URIs. Comparing the two finds a dependency that
is declared but never observed, or observed but never declared.

> `qr-graph` is a development tool. It ships in this package under its own namespace
> (`BEAR\QueryRepositoryGraph`) for now and is planned to move to a separate package you install with
> `require-dev`. Its contract is the command line and the schemas below, not the PHP classes.

## Commands

```bash
vendor/bin/qr-graph dump [--dir=src/Resource]
vendor/bin/qr-graph impact <uri> [--method=onPut] [--dir=src/Resource]
vendor/bin/qr-graph dot [--dir=src/Resource]
```

Run it from the application root: classes are loaded through the application's autoloader, and a file
the autoloader does not resolve is reported on stderr as skipped, never included. Output is JSONL on stdout;
diagnostics are JSONL on stderr. Exit code: 0 ok, 1 error, 2 usage.

### `dump` — one line per resource

The first line is `meta`; every other line is a resource and the declarations it makes
([schema](schemas/graph/dump.json)). Edges start at the resource that declares them, so a line has no `from`.

```json
{"uri":"app://self/user","source":"src/Resource/App/User.php:17","cacheable":{"expiry":"never","expirySecond":0,"expiryAt":"","update":false,"type":"value"},"methods":{"onPut":{"source":"src/Resource/App/User.php:52","refresh":[{"uri":"app://self/user","implicit":"Cacheable"},{"uri":"app://self/user/profile","template":"app://self/user/profile?user_id={id}"}],"purge":[{"uri":"app://self/user/friend","template":"app://self/user/friend?user_id={id}"}]}}}
```

- `uri` is `scheme://host/path` without query, exact-matchable everywhere it appears; `template` keeps the
  declaration as written. The PHP class is not repeated: it follows from `uri` and `meta.namespaces`.
- `source` is `file:line`, to open the declaration.
- `implicit` marks a refresh nobody wrote: a write method of a `#[Cacheable]`/`#[CacheableResponse]` class,
  or one marked `#[RefreshCache]`, refreshes its own resource.
- `dynamic` lists source-text hints that the declarations may not be the whole story (the class source
  mentions a `surrogate-key`, or `ResourceInject`/`ResourceInterface` for a `resource-request` outside `#[Embed]`). A hint is a reason to read the source; its absence proves nothing.

Local questions are a `grep` away: `grep '"uri":"app://self/user/profile"'` finds the resource, and
`grep 'app://self/user/profile"'` also finds every line that embeds, refreshes or purges it.

### `impact` — what an invalidation reaches

Multi-hop questions ("if this changes, what goes stale?") are answered by `impact`, not by repeated `grep`.
It walks the embed edges breadth-first, one line per reached URI by its shortest path
([schema](schemas/graph/impact.json)). With `--method`, it starts from what that write method refreshes and purges.

```json
{"uri":"page://self/dep/diamond-top","via":"embed","by":"page://self/dep/diamond-left","depth":2,"cached":true,"source":"src/Resource/Page/Dep/DiamondTop.php:10"}
```

`by` leads back to the start. `cached: false` on the resource named by `by` means the cache does not carry
that dependency: a child's tags reach its parent only from a child that ran with an ETag or Surrogate-Key.

### `dot` — a picture

```bash
vendor/bin/qr-graph dot | dot -Tsvg > graph.svg
```

Arrows point as declared (a page to what it embeds, a write to what it refreshes or purges); add
`edge [dir=back]` for the direction invalidation travels. Cached resources are drawn double-bordered,
resources with `dynamic` hints dashed.

## What the graph does not know

- Surrogate keys set in code and resources requested in code (flagged as `dynamic`, not resolved).
- Which instance a template reaches: `{id}` is filled from the request at run time. That is the log's part.
- Bindings: the graph reads attributes, not the application's modules, so it does not know whether
  `DonutCacheModule` is installed or a CDN purger is bound.
