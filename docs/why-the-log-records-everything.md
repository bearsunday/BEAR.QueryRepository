# Why the QueryRepository Log Records Everything

BEAR.QueryRepository ships with cache logging that is unusually thorough: an
open/event/close tree, two dozen typed context classes, one JSON Schema per
context, schema validation inside the test suite, and demos that refuse to
exit 0 unless their own log validates. This document explains why that scale
is deliberate — the failure class it exists to eliminate, the principles that
shaped it, and what it costs you.

## Caches fail silently

A distributed cache with event-driven invalidation is invisible machinery.
When it breaks, nothing throws. The application keeps answering 200 — with
stale content. Silent wrongness is the most expensive failure mode in
software, and cache invalidation is its natural habitat: the difference
between "fresh" and "stale forever" is not an exception anyone can catch, it
is the absence of one invalidation that nobody saw not happen.

This is not hypothetical for this package. Until v1.16.1, a `#[Cacheable]`
resource embedding two or more children silently lost its dependency on every
child but the last (`CacheDependency::depends()` overwrote the accumulated
surrogate keys instead of appending). Purging a lost child did not invalidate
the parent: stale pages, indefinitely, no error, in production. The bug lived
for years — and it lived *alongside a cache log*. The old flat log even
printed the surrogate keys. Nobody could see the missing dependency edge in a
stream of strings, because the log had no structure to make the dependency
graph visible and no contract to make its records checkable.

That incident defines the goal: **every decision the cache makes must be
observable — in a form a human can read, a test can pin, and a machine can
verify.**

## What the log is

**The tree is the domain structure.** A GET opens a scope; the GETs of its
embedded children nest inside it; the scope closes with the hit/miss outcome.
A write opens a `command` scope recording the method, its
`#[Refresh]`/`#[Purge]` annotations and the interceptor that ran them, with
the resulting invalidations nested beneath — cause and effect in one subtree.
The dependency graph you must trust is the shape you see.

**Every context is one decision point.** The vocabulary — `cache_hit`,
`cache_miss`, `depends_on`, five `save_*` kinds, `invalidate`, `purge`,
`put_skipped`, `cache_error`, `pre_write_cleanup`, the `manual_*` scopes
— is not verbosity. It enumerates the decisions the cache
subsystem makes at the boundaries this package owns; the decisions it leaves
silent are listed in [what-the-log-proves.md](what-the-log-proves.md). There are five save contexts because
there are five physically different write paths; merging them would erase
exactly the distinction a debugging session needs. The size of the vocabulary
is a fact about the domain, not a fault of the design.

## The principles

**1. No silent paths.** Every code path either emits a fact or emits why it
didn't. A store records its `saved` outcome — a pool that rejects a write no
longer looks like a successful save. A miss not followed by a put emits
`put_skipped` with the reason (`etag-present`, `error-code` with the actual
response code, `not-cacheable`) — an absent save is a recorded decision, not
a mystery. Invalidation reports per-target outcomes (`roPool`/`etagPool`:
`invalidated`|`failed`; `cdn`: `purged`|`failed`|`skipped`) plus its
duration. A failed write still opens a command scope, closed with its 4xx —
recording that the purge was *correctly skipped*.

**2. Facts are recorded at the source, never inferred by the reader.** The
writer knows why it invalidates; the reader should not have to guess. The
`pre_write_cleanup` marker exists because this package tried the alternative:
an earlier revision classified cleanup-vs-invalidation with a reader-side
tag-correlation rule, which took two rounds to make decidable and still ended
with an undecidable donut case. Recording one marker at the write site
deleted the whole procedure. Inference rules rot; recorded facts don't.

**3. "Unknown" is never dressed as "nothing happened".** A `cache_error`
event records that the cache path failed and, in `exceptionClass`, what failed
it — separating a degraded cache from a cold one (a miss after a pool outage is
not a missing entry) and both from a bug in the work the store performs, such
as a view that fails to render. If the logging protocol itself is misused — a LIFO
violation, a session left unclosed at flush — the core logger records a
`semantic_logger_error` diagnostic in-band at the exact failure point, with the
tree preserved. A log that can lose data must say so in-band.

**4. The log is a contract, not prose.** Every context carries a `TYPE` and a
`SCHEMA_URL`; every type has a JSON Schema in `docs/schemas/context/`. The
test suite validates every emitted tree against those schemas
(`SemanticLogTreeTrait`), and the demos validate their own output offline and
exit non-zero on violation. Code and schema cannot drift without failing the
build. That inverts the usual decay: ordinary logs rot into lies the moment
behavior changes; this log is an executable specification of the cache's
recorded shape, which CI keeps true. Two behaviours it does not execute are the
CDN's HTTP API (exercised with fakes) and wall-clock expiry (verified by
construction) — both declared in [what-the-log-proves.md](what-the-log-proves.md).

## Who reads it

**Humans.** `vendor/bin/stree` renders a session as a tree
(`php demo/run-dependency.php`): hits with no events, misses with their
cleanup-invalidate-save sequence, dependency edges, command cascades.

**Tests.** The tree shapes are pinned in `SemanticLogSchemaTest` and friends —
the log doubles as regression armor. The v1.16.1 bug class is now
structurally visible: a parent scope whose `save_etag` tags accumulate all of
its children is one assertion away.

**Machines.** This is the part built for the AI era. An agent diagnosing your
cache does not grep strings and guess: it flushes a session, gets a tree
whose every node links its own schema, and reasons mechanically —
a pool `cache_error` before a miss means outage, not cold cache, while its
`exceptionClass` tells whether the pool was the thing that failed at all;
`put_skipped{error-code, 404}` means the non-store was deliberate; an
`invalidate` without a `pre_write_cleanup` marker is a real cache bust.
Ground truth plus published schemas turn cache debugging from folklore into
verification. The reading rules an agent needs ship with the package
(`docs/llms.txt`, `docs/llms-full.txt`).

## What it costs — and why it is off by default

Measured on a three-level embedded page, one request per cache hit, 2000 requests per run
(PHP 8.4, no JIT, in-memory pools — the ratio transfers, the absolute figures do not):

| | per request | accumulated per request, unflushed |
|---|---|---|
| `NullSemanticLogger` | 0.018 ms | 4 B |
| `SafeSemanticLogger` | 0.023 ms | 1409 B |

CPU is not the question — 5 µs is a fraction of one cache round-trip. The
accumulation is: 1.4 KB per request is free under PHP-FPM, where the session
dies with the process, and unbounded in a worker that never flushes.

Two other measurements decide the rest, and they are different arguments.

**Density.** Counting the four demos' own output, a healthy session contains
**zero** failure entries out of 28 to 95 — and even `run-degraded.php`, built
entirely out of failures, is 10% failure and 90% scaffolding. There is nothing in
a healthy session to read.

**Volume.** A pure-hit session is 698 B as one JSON line; the sessions production
deliberately keeps — commands, cleanup-invalidate-save chains, manual calls —
measure 3.9 KB median and 21 KB worst case across the demos. At 1000 requests per
minute, writing every session is about 1 GB per day; keeping only mutations and
missing effects turns that into the write rate times 3.9 KB, which for an app
writing 1% of its requests is about 56 MB per day. The retention policy is that
factor, not a preference.

So the default is silence, exactly as it is for the cache engine
(`NullAdapter`): nothing recorded, nothing accumulated, no flush owed. An app
turns the log on for development, where it is read:

```php
$this->install(new DevQueryRepositoryLogModule($appDir . '/var/log/query-repository', module: new QueryRepositoryModule()));
```

and, in production, keeps only the sessions nothing else can account for —
mutations, effects that did not happen, and a sample:

```php
$this->install(new ProdQueryRepositoryLogModule('php://stdout', sampleRate: 1000, module: new QueryRepositoryModule()));
```

Flushing comes with the module, not with a line in the bootstrap: it registers
a shutdown-time flush, which is the only boundary that survives every way a
request ends — after output, after a 304's early `exit()`, after an uncaught
error.

The side-channel stays a side-channel by construction: since
koriym/semantic-logger 0.9 the logger is total — it never throws, and records
protocol misuse as in-band `semantic_logger_error` diagnostics — and the sink
reports a destination that fails instead of raising, so neither a logging failure
nor a full disk changes how the request ended, and neither can hide.

Two states remain where the log is partial, and both are declared rather than
denied: on a host the sink can prove is concurrent it refuses to record at all,
and on one it cannot detect (a Swoole worker built at boot, FrankenPHP worker
mode, ReactPHP, Amp, a long-lived CLI consumer) sessions accumulate and interleave
until the operator binds a request-scoped sink. In production, a third: the
retention policy drops healthy reads, so absence stops meaning "the code decided
not to" and starts meaning "not kept" — the reading rules that depend on absence
are development-time ones. See [what-the-log-proves.md](what-the-log-proves.md).

## Pointers

- Reading rules and context tables: [docs/llms-full.txt](llms-full.txt) (Cache Log section)
- Per-context schemas: [docs/schemas/context/](schemas/context/)
- Self-verifying demos: `demo/run-dependency.php`, `demo/run-donut.php`, `demo/run.php`, and `demo/run-degraded.php` (the failure vocabulary: skipped stores, refused writes, a downed cache, a failed CDN purge)
- Test-side view: [tests/CACHE_DEPENDENCY_TESTS.md](../tests/CACHE_DEPENDENCY_TESTS.md)
- What the log proves and what it does not: [docs/what-the-log-proves.md](what-the-log-proves.md)
