# Reading the Log

Every word the cache log can contain, and how to read a session. It assumes you run a
BEAR.Sunday application with this package installed: the cache model itself — `#[Cacheable]`,
donut caching, event-driven invalidation — is the
[cache manual](https://bearsunday.github.io/manuals/1.0/en/cache.html), not this page. The per-context JSON Schemas
under [docs/schemas/context/](schemas/context/) are the contract; this page is the reader's
guide to them. `docs/llms.txt` and `docs/llms-full.txt` carry the same vocabulary as a lookup
table for an agent — update both when a context changes.

Recording is off by default. To produce a session, install a log module
([why](why-the-log-records-everything.md)):

```php
$this->install(new DevQueryRepositoryLogModule($appDir . '/var/log/query-repository', module: new QueryRepositoryModule()));
```

```bash
vendor/bin/stree var/log/query-repository/latest.json   # the request that just ran, as a tree
```

## Terminology

The concepts — conditional requests, ETag, donut caching, surrogate keys — are the
[cache manual's Terminology](https://bearsunday.github.io/manuals/1.0/en/cache.html). This table
is narrower: it expands the names this log uses for its own moving parts, so an identifier you
meet in an entry can be traced to the thing it names in your application.

| In the log | Is | Where you meet it in your app |
|---|---|---|
| `roPool` | the **R**esource **O**bject pool: the store holding cached bodies, views and donut templates | the adapter you bind with `#[ResourceObjectPool]` |
| `etagPool` | the store holding validators, keyed by ETag | the adapter you bind with `#[EtagPool]` |
| `tags` | the invalidation namespace an entry is stored under — surrogate keys | `Header::SURROGATE_KEY`, and `UriTagInterface` for a URI turned into a tag |
| a URI tag | one resource's URI as a tag, which is also the surrogate key its parents carry | `($this->uriTag)(new Uri('app://self/foo'))` |
| `etag` | the entity-tag of a representation: a validator, not a key | the `ETag` response header |
| `layer` | which store answered a lookup, not which pool was written | — (a log-only axis) |
| donut / donut-view | the cacheable shell with holes / the recomposed page | `#[CacheableResponse]`, `#[DonutCache]` |
| `sMaxAge` | the shared-cache lifetime a write asked the CDN for | `DonutRepositoryInterface::put($ro, ttl: …, sMaxAge: …)` |
| a scope, an event, a close | the three node kinds of the tree, described next | — (a log-only distinction) |

`roPool` and `etagPool` name **pools**; `layer` names **which store answered**. They read as if
they overlap and do not: a `get` closing `cache_hit{layer: resource}` says the resource store
answered, while an `invalidate` reporting `roPool: invalidated` says that store dropped the tags.

## The shape

A session is one tree per request. Nesting is not chronology — it is the structure of the work:

```text
get page://self/html/blog-posting          ← a scope: opened, then closed
  get page://self/html/comment             ← an embedded child, nested inside its parent
    save_value {tags, ttl, saved}          ← an event: something that happened in this scope
    cache_miss {layer: resource}           ← the close: how the scope ended
  put_donut {ttl, sMaxAge}
  cache_hit {layer: donut-view}
```

Three node kinds, and the distinction is the whole grammar:

| Kind | Meaning | Read it as |
|---|---|---|
| **open** | a scope that was entered | "this work started" — its children are what happened inside |
| **event** | a fact recorded inside the active scope | "this happened, with this outcome" |
| **close** | how a scope ended | the scope's verdict — one word |

One type can appear at either position, and the `layer` tells you which: a `cache_hit`/`cache_miss`
**event** is an inner lookup (`donut` — the donut template, probed by `DonutRepository`), while a
`cache_hit`/`cache_miss` **close** is the scope's own answer (`resource` from the `#[Cacheable]`
path, `donut-view` for a donut page, `etag` for a conditional request). So a scope can hold a miss
and still close with one, at a different layer.

## Scopes (open/close)

| Type | Opened by | Closes with |
|---|---|---|
| `get` (`uri`) | a resource GET through the cache | `cache_hit` / `cache_miss` |
| `command` (`method`, `annotations`, `source`) | a write (`onPut`/`onPatch`/`onDelete`) | `command_result` (`code`) |
| `conditional_request` (`ifNoneMatch`) | the transfer boundary checking `If-None-Match` | `cache_hit` / `cache_miss` at `layer: etag` |
| `manual_store` (`uri`) | a direct `put()` / `putStatic()` / `putDonut()` | `manual_store_result` (`stored` \| `failed`) |
| `manual_purge` (`uri`) | a direct `purge()` | `manual_purge_result` (`purged` \| `failed`) |
| `manual_invalidate` (`tags`) | a direct `invalidateTags()` | `manual_invalidate_result` (`invalidated` \| `failed`) |

`manual_*` means **application-initiated**: the call had no framework scope around it. The same
operation inside a GET or a command is an ordinary event there instead.

## Events

| Type | Fields | What it tells you |
|---|---|---|
| `save_value` | `uri`, `tags`, `ttl`, `saved` | the body was offered to the pool |
| `save_view` | `uri`, `tags`, `ttl`, `saved` | body + rendered view were offered |
| `save_etag` | `uri`, `etag`, `tags`, `ttl`, `saved` | the validator was offered to the ETag pool |
| `save_donut` | `uri`, `tags`, `ttl`, `saved` | the donut template was offered |
| `save_donut_view` | `uri`, `tags`, `ttl`, `saved` | the recomposed donut view was offered |
| `put_donut` | `uri`, `ttl`, `sMaxAge` | a donut write was requested, with the lifetime asked for |
| `refresh_donut` | `uri` | a cached donut was recomposed rather than served as-is |
| `cdn_headers` | `uri`, `headers`, `surrogateKeys` | the CDN-facing headers the response actually carried |
| `depends_on` | `parent`, `child`, `childTags` | one dependency edge: the child's tags were added to the parent |
| `pre_write_cleanup` | `uri` | the writer is about to clear the entry it will rewrite |
| `invalidate` | `tags`, `roPool`, `etagPool`, `cdn`, `durationMs` | tags were invalidated, with a result per target |
| `purge` | `uri` | a URI-targeted bust was requested |
| `put_skipped` | `uri`, `reason`, `code` | a miss was **not** followed by a write, and why |
| `cache_hit` / `cache_miss` | `layer` | an inner lookup, always `layer: donut` — whether the donut template was there |
| `cache_error` | `uri`, `operation`, `error`, `exceptionClass` | the cache path threw |
| `semantic_logger_error` | `kind`, `message`, … | the logger itself was misused (core diagnostic, not this package's vocabulary) |

## The words that carry outcomes

Every outcome is a self-describing word, never a bare boolean — except `saved`, which is one:

| Field | Values | Reading |
|---|---|---|
| `layer` | `resource` \| `donut` \| `donut-view` \| `etag` | which store was asked. `resource` = the `#[Cacheable]` value/view store; `donut` = the donut template (only ever an event); `donut-view` = the recomposed donut page; `etag` = the ETag pool behind a conditional request |
| `saved` | `true` \| `false` | **`false` = the pool refused the write.** Nothing else in the system records this |
| `roPool` / `etagPool` | `invalidated` \| `failed` | per-pool invalidation result |
| `cdn` | `purged` \| `failed` \| `skipped` | `skipped` = no purger configured (`NullPurger`), not "nothing to do" |
| `operation` | `read` \| `write` | which side of the cache threw |
| `reason` (`put_skipped`) | `etag-present` \| `error-code` \| `not-cacheable` | why no write happened. `etag-present` = the resource already carried an ETag, so the donut layer left it alone; `not-cacheable` = a donut page re-rendered from its template, which is never stored as a page; `error-code` carries the response `code`, and the threshold differs by path: `#[Cacheable]` skips any non-200 (a `203` appears here), a donut skips 4xx and above |
| `result` (`manual_*`) | `stored`/`purged`/`invalidated` \| `failed` | the direct call's outcome |
| `ttl` | seconds | the cache entry's own lifetime. `31536000` is the `never` convention; `0`/`null` = no expiry set |
| `sMaxAge` (`put_donut`) | seconds | the shared-cache (CDN) lifetime the write asked for — the same argument as `DonutRepositoryInterface::put($ro, ttl: …, sMaxAge: …)`, and not the entry's own `ttl`. `null` = none requested, and `putDonut` always records `null` |
| `code` (`put_skipped`) | HTTP status | present only when `reason` is `error-code`; `null` for the other two reasons |

## Reading rules

These are the ones you cannot guess from a field name.

**A miss with no `save_*` is not a lost write.** Look for `put_skipped` — it records that the
non-write was deliberate, with the reason.

**`cache_error` + `cache_miss` = degraded, not cold.** A lone `cache_miss` means the entry was
absent. The pair means the pool failed and the resource ran anyway. This is a development-time
reading: production keeps neither, so there the pair's absence proves nothing (see *Finding the
session*).

**An `invalidate` is pre-write cleanup iff a `pre_write_cleanup` marker sits immediately before
it in the same scope.** A writer clears the entry it is about to rewrite, which looks identical
to a real bust. The marker is recorded at the source, so nothing is inferred from tag
correlation. Any `invalidate` without the marker is a real invalidation.

**Dependency correctness is a set intersection.** Correlate the `tags` of a `save_*` with the
`tags` of a later `invalidate`. If they do not intersect, the write did not bust that entry —
which is what serving stale looks like from the inside.

**`cdn_headers` shows what the response really carried**, including a CDN module's silent
default. No lifetime header in the map means the response gave the CDN no lifetime directive.
Correlate its `surrogateKeys` with an `invalidate`'s `tags` to see whether a purge could reach
what the edge holds.

**A `conditional_request` closing `cache_hit{layer: etag}` is a 304** — the whole request
answered from the ETag pool without running the resource. No `get` scope can show this.

**A donut `cache_hit` does not distinguish served-from-cache from recomposed.** The close reports
the final layer only; look for `refresh_donut` inside the scope.

## Worked example

Verbatim from `demo/run-dependency.php` — a PUT on a resource two other resources embed:

```text
command {"method": "onPut", "annotations": [], "source": "CommandInterceptor"}
  get {"uri": "page://self/dep/level-three"}
    pre_write_cleanup {"uri": "page://self/dep/level-three"}
    invalidate {"tags": ["_dep_level-three_"], "roPool": "invalidated", "etagPool": "invalidated", "cdn": "skipped"}
    save_etag {"uri": "page://self/dep/level-three", "tags": ["_dep_level-three_"], "ttl": 31536000, "saved": true}
    save_value {"uri": "page://self/dep/level-three", "tags": ["_dep_level-three_"], "ttl": 31536000, "saved": true}
    cache_miss {"layer": "resource"}
  purge {"uri": "page://self/dep/level-three"}
  invalidate {"tags": ["_dep_level-three_"], "roPool": "invalidated", "etagPool": "invalidated", "cdn": "skipped"}
  command_result {"code": 200}
```

Read it in order:

1. A write ran: `onPut`, driven by `CommandInterceptor`. `annotations` is empty, so no
   `#[Refresh]`/`#[Purge]` attribute chose the target — the command refreshed its own URI. When an
   attribute does choose one, each entry is `{"class": "…\\Annotation\\Refresh", "uri": "app://self/refresh-dest{?id}"}`:
   the class says which attribute, the uri says its target, and `{?id}` is the URI template whose
   parameters come from the command's own arguments.
2. The nested `get` is that refresh, and it is a scope of its own: the resource really ran
   (`cache_miss{layer: resource}`) and was stored (`saved: true` twice - body and validator).
3. The **inner** `invalidate` follows a `pre_write_cleanup` for the same URI: that is the writer
   clearing the entry it is about to rewrite, not a bust.
4. The **outer** `invalidate` has no marker before it, so it is a real invalidation. Its tag is
   the child's URI tag, which is also the surrogate key its two parents were stored under - this
   single event is what makes the embedding resources stale.
5. `cdn: skipped` twice: no purger is configured here. On a CDN-backed app these would read
   `purged`, and a `failed` would mean the local pools were cleared while the edge was not.
6. `command_result{code: 200}` closes it. A 4xx here with no `invalidate` above would be the
   record of a failed write correctly busting nothing.

## Finding the session

A session carries **no clock and no request id**. `LogJson` is `$schema`, `open`, `close`, `events`
and `links` — nothing else. So correlating a session with one customer request is the host's job,
not the log's:

| | What identifies a session |
|---|---|
| `DevQueryRepositoryLogModule` | the file name, UTC to the microsecond (`20260816-120000-123456.json`), and `latest.json` for the request that just ran |
| `ProdQueryRepositoryLogModule` | nothing inside the line. The collector's own line timestamp is the only clock, and there is no request id to join on |
| Need a request id? | decorate `LogWriterInterface` and add it to the line — the same seam used for scrubbing and rate-limiting |

**In production, absence is not evidence.** The retention policy drops healthy reads and read-side
outages, so a session that is not there may have been dropped rather than never have happened. Only
the categories the policy keeps can be reasoned about, and only positively: "we invalidated these
tags" is supportable, "no invalidation ran" is not. In development, where every session is written,
absence does mean the code decided not to act — that is where the "no silent paths" property holds.

## Where it goes

| | Destination | Policy |
|---|---|---|
| `DevQueryRepositoryLogModule` | one file per session + `latest.json` | everything |
| `ProdQueryRepositoryLogModule` | one JSON line per session to `php://stdout` or a file | mutations, effects that did not happen, an optional sample |
| | `sampleRate: N` keeps 1 healthy session in N (`0` = none). A retained session measured 3.9 KB median, 21 KB worst case across the demos, against 698 B for a pure hit | |
| | Under PHP-FPM `php://stdout` reaches the pool's output only with `catch_workers_output = yes`; in a container it goes to the collector. Transport, rotation and retention are the host's | |
| `PsrLogWriter` | the app's PSR-3 logger, tree in `context['log']` | whatever writer it wraps |

A session carries request URIs **with their query strings**, the client's `If-None-Match`, cache
tags, CDN header values and raw exception text. Treat a written session as an application log:
`LogFileWriter` creates 0700 directories and 0600 files. To scrub or rate-limit, decorate
`LogWriterInterface` — that is the seam, and it is also the answer to a pool outage, during which
every request emits `cache_error` and retention rises to 100%.

## Pointers

- Guarantees and boundaries: [what-the-log-proves.md](what-the-log-proves.md)
- Why it records everything, and what it costs: [why-the-log-records-everything.md](why-the-log-records-everything.md)
- Per-context schemas: [schemas/context/](schemas/context/)
- Self-verifying demos: `demo/run.php`, `demo/run-donut.php`, `demo/run-dependency.php`, `demo/run-degraded.php`
