# What the Cache Log Proves — and What It Does Not

The semantic cache log makes one claim: **a reader of the log alone, without this
package's source code, can answer the operational questions below.** This document
states each question, the events that answer it, and the mechanism that keeps the
answer honest. It ends with the declared boundaries — what the log does not record,
and why.

"Proves" here means: the answer is recorded at the site where it becomes true,
validated against a published JSON Schema, demonstrated by an executable demo the
test suite cannot pass without, and defended by tests that fail when the recording
is removed or its meaning inverted (verified by mutation testing).

## The questions the log answers

| # | Operational question | Answered by | Kept honest by |
|---|---|---|---|
| 1 | Was this response stored — with what lifetime, under which keys, and did the write succeed? | `save_value` / `save_view` / `save_etag` / `save_donut` / `save_donut_view` `{tags, requestedTtl, saved}` | Schemas declare `requestedTtl` ≥ 0; every store clamps a negative lifetime and a test walks all five; both `saved` outcomes must appear in the demos |
| 2 | What was the CDN told to cache, and for how long? | `cdn_headers` `{headers, surrogateKeys}` at every donut write and refresh: the literal response headers, read back after they are final | Each flavor's silent default is pinned — generic `max-age=10`, Fastly/Akamai `max-age=31536000` — which is the framework's rule and not a typo: a CDN that can invalidate by tag gets a year, one that cannot gets ten seconds ([cache manual](https://bearsunday.github.io/manuals/1.0/en/cache.html)). Akamai's `Surrogate-Key` → `Edge-Cache-Tag` rename is pinned too; no lifetime header recorded = the response gave the CDN no lifetime directive (the `putDonut` kind) |
| 3 | Was the CDN told to purge — exactly what, and did it work? | `invalidate` `{tags, roPool, etagPool, cdn, durationMs}`; the purger receives exactly the recorded tags | `cdn` is tri-state (`purged` / `failed` / `skipped`) and fail-closed: local pools first, outcome logged, then the purge exception propagates. One refusing pool is enough to report failure |
| 4 | Did a conditional request revalidate at the edge? | `conditional_request` `{ifNoneMatch}` closing `cache_hit`/`cache_miss` at layer `etag` — the 304 decision, made before any resource runs | Both `HttpCacheInterface` implementations record it; dropping either recording or swapping the outcome fails the suite |
| 5 | Why is there no entry — was nothing stored (cold), or could the store not be read (degraded: the framework ran the resource as if there were no cache)? | `put_skipped` `{reason, code}`, `cache_error` `{operation, exceptionClass}` — `operation: read` paired with the still-closing `cache_miss` is a degraded read | Skip reasons, the failing side (`read`/`write`) and the throwable class are pinned; `cache_error{read}` + `cache_miss` = degraded read, lone `cache_miss` = cold |
| 6 | Who initiated this write or invalidation — the framework or the application? | `command` scopes name their producing interceptor (`source`); direct calls root in `manual_store` / `manual_purge` / `manual_invalidate` with the outcome on the close; `pre_write_cleanup` marks a writer's own cleanup | A write that throws closes `manual_store_result{failed}` — a scope closing `stored` while the caller catches an exception is the log lying, and a test forbids it |

## The enforcement layers

1. **Schemas** — every context type has a published JSON Schema
   (`docs/schemas/context/`). Every test flush validates against them with
   diagnostics treated as failures, so a logging-protocol regression fails the
   suite even though the logger never throws.
2. **Self-verifying demos** — `demo/run.php`, `run-donut.php`, `run-dependency.php`,
   `run-degraded.php` print the session tree and validate it offline, exiting
   non-zero on any violation.
3. **Vocabulary closure** — `tests/DemoLogCoverageTest.php` fails unless every
   context class, every schema `enum` value, both `saved` outcomes and all three
   `command.source` producers appear in the demo output. A context that exists but
   is never demonstrated cannot ship.
4. **Sequence pins** — the exact event order of the donut writes is asserted, so a
   new or removed emission is a conscious, reviewed change.
5. **Mutation verification** — the pins above were selected by running mutation
   testing against the changed sources and killing the survivors that mattered:
   removing an emission site, inverting an outcome word, changing a default,
   widening a boundary. Line coverage alone caught none of these.
6. **Record at the source** — the effect is recorded where it becomes final, never
   inferred by the reader: lifetimes are clamped where they are logged, CDN headers
   are read back from the response after the setters ran, cleanup is marked by the
   writer that performs it.

## What the log does not record

- **What the CDN did with what it was told.** The log records the headers sent and
  the purge outcome the purger reported. Edge propagation delay, eviction and
  regional behavior are the CDN's, and the Fastly/Akamai HTTP APIs are exercised
  with fakes in this repository's tests.
- **`#[HttpCache]` static header configuration.** A class attribute with no runtime
  state, emitted identically on every response; it never touches the repository.
  The log records runtime-determined facts.
- **Custom CDN header names.** `cdn_headers` covers the headers this package's
  setters manage (`CDN-Cache-Control`, `Surrogate-Control`, `Akamai-Cache-Control`,
  `Surrogate-Key`, `Edge-Cache-Tag`). A custom
  `CdnCacheControlHeaderSetterInterface` using its own header name is outside the
  log's knowledge.
- **Wall-clock expiry.** TTL arithmetic (clamping, remaining lifetime, `Age`) is
  verified by construction, not by elapsed-time tests; actual eviction at the
  recorded moment is the cache backend's contract.
- **Concurrent sessions.** The logger holds one session per injector. Where the sink can prove
  the host is concurrent — a RoadRunner worker, or inside a Swoole coroutine — it refuses to arm
  and recording stops with it, because nothing would drain the session. Such a host binds a
  request-scoped `LogSinkInterface`, or leaves the log module out (#179). Hosts it cannot detect
  (a Swoole worker whose logger is built at boot, FrankenPHP worker mode, ReactPHP, Amp, a
  long-lived CLI consumer) are the operator's call.
- **A `ResourceStorage::hasEtag()` call outside a conditional request.** The
  semantic event is "a conditional request was answered", owned by the transfer
  boundary (`HttpCache` / `CliHttpCache`); the storage query itself is silent.
- **Sessions a retention policy dropped.** `DevQueryRepositoryLogModule` writes every session;
  `ProdQueryRepositoryLogModule` keeps only what nothing else can account for, so in production a
  healthy read is deliberately absent, and so is a failed read — every one of those already reaches
  the application's warning channel, since both interceptors `trigger_error()` before degrading.
  Separating a degraded miss from a cold one is therefore a development-time reading.

## Reading pointers

- Vocabulary, one row per context: [llms-full.txt](https://bearsunday.github.io/BEAR.QueryRepository/llms-full.txt) / [tests/CACHE_DEPENDENCY_TESTS.md](../tests/CACHE_DEPENDENCY_TESTS.md)
- Design rationale: [why-the-log-records-everything.md](why-the-log-records-everything.md)
- Schemas: [docs/schemas/context/](schemas/context/)
- Rendering: `vendor/bin/stree` (the demos show the tree form)
