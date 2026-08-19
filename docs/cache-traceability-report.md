# Cache Traceability Report — Next Release (1.16.2 → Unreleased)

How much the next release improves cache **traceability** and **explicitness**, covering the whole Unreleased section including the seven PRs merged on 2026-08-19 (#191, #192, #193, #194, #195, #196, #198).

## 1. What 1.16.2 could not observe

The previous log was a flat op-string format (`RepositoryLoggerInterface`). The following failure and decision classes went unrecorded — indistinguishable to a log reader.

| # | Silent condition | How it looked before |
|---|---|---|
| 1 | Cache backend down (Redis/Memcached unreachable) | Identical to a run of ordinary misses (symfony/cache adapters answer miss/false instead of throwing) |
| 2 | Pool refusing a write (save returning `false`) | Identical to a successful save |
| 3 | No CDN purger configured (NullPurger) | Identical to a real purge (`purged`) |
| 4 | CDN purge failure | Local invalidation happened, CDN stayed stale, nothing recorded |
| 5 | Value-entry stores depending on rendering; a renderer that throws degraded every store to a warning (#193) | Cache stayed empty, no reason recorded |
| 6 | Donut templates of pages declaring no `Surrogate-Key` stored untagged, unreachable by any `invalidateTags()` (#194) | Purge dropped content and validator, leaving an immortal shell, unrecorded |
| 7 | 1.16.0 regression: declaring a custom `Surrogate-Key` lost embed dependency tracking (#195) | Purged children kept being served, unrecorded |
| 8 | Logged TTL contradicting the stored TTL (negative lifetimes recorded verbatim), the misleading `sMaxAge` label | The log contradicted the store's facts |
| 9 | Which declaration decided an entry's lifetime (preset / `expirySecond` / `expiryAt`) (#196) | The resolved number alone cannot separate `never` (live until invalidated) from a deliberate 1-year TTL |
| 10 | The 304 decision itself (the whole request answered from the ETag pool) | Appeared in no `get` scope |
| 11 | Who initiated a write or invalidation — framework or application | Indistinguishable |
| 12 | Hijack of the application's own `SemanticLoggerInterface` binding (#191) | Silently swapped depending on install order |

## 2. What the next release records

| # | Condition | Recording context |
|---|---|---|
| 1 | Store down | `pool_error {key, operation, error, exceptionClass}` — the adapter's own report, carried |
| 2 | Write refused | `saved` field on all five save contexts (accept/reject) |
| 3 | No CDN configured | `invalidate.cdn = skipped` (tri-state: `purged` / `failed` / `skipped`) |
| 4 | CDN purge failure | `invalidate {cdn: failed}` + fail-closed (local pools first, outcome logged, then the exception propagates) |
| 5 | Store without rendering | Fixed. The value path falls back to the body with `$ro->view === null`, and the ETag follows the body |
| 6 | Untagged template | Fixed. Templates are stored under their own URI tag, so `purge($uri)` reaches them |
| 7 | Lost dependency tracking | Fixed. Declared keys and embed dependencies coexist; tracking is recorded as `depends_on` |
| 8 | TTL contradiction | Fixed. The requested lifetime is clamped where recorded; the field is `requestedTtl` — what was asked, not the store's effective lifetime |
| 9 | Declared lifetime | `cache_policy {expiry, expirySecond, expiryAt, resolvedTtl}` — exactly the deciding declaration is recorded, readable against `resolvedTtl` |
| 10 | 304 decision | `conditional_request {ifNoneMatch}` closes with `cache_hit`/`cache_miss` at layer `etag` |
| 11 | Initiator | `command.source` (the producing interceptor) and `manual_store` / `manual_purge` / `manual_invalidate` scopes, outcome on the close |
| 12 | Binding hijack | Separated behind the `#[CacheLog]` qualifier; the application's binding stays untouched |

## 3. The mechanisms behind explicitness, quantified

- **28 typed contexts** (`src/Log/Context/`), each with a published JSON Schema (`docs/schemas/context/`, 28 files, 9 with enums)
- **Tree structure = dependency structure**: open/event/close nesting *is* the embed/dependency structure — a parent's children hang under it
- **Record at the source**: effects are recorded where they become final (lifetimes clamped where logged, CDN headers read back after the setters ran, cleanup marked by the writer via `pre_write_cleanup`)
- **unknown ≠ absent**: what cannot be discriminated is recorded as `unknown`, never guessed (e.g. the `operation` fallback)

## 4. Enforcement layers defending the claims

1. **Schema validation** — every test flush is validated against the published schemas with diagnostics treated as failures (`failOnDiagnostics`); a logging-protocol regression fails the suite even though the logger never throws
2. **Self-verifying demos** — four scripts (`run.php` / `run-dependency.php` / `run-donut.php` / `run-degraded.php`) print the session tree and JSON and validate offline, exiting non-zero on any violation
3. **Vocabulary closure** — `DemoLogCoverageTest` (6 tests / 22 assertions) requires every context class, every schema enum value, both `saved` outcomes per save context, and all `command.source` producers to appear in demo output; an undemonstrated context cannot ship
4. **Sequence pins** — the exact event order of the donut writes is asserted, so any added or removed emission is a reviewed change
5. **Mutation verification** — pins were selected by mutation testing: removing an emission site, inverting an outcome word, changing a default

## 5. Measured evidence

- Full suite: **283 tests / 812 assertions, green** (1.x tip `283badd`)
- Every fix PR's pin tests were confirmed FAIL on the old code (reproduction before fix):
  - #193: old code reached `FakeThrowingRenderer` and degraded to a warning; the ETag did not follow the body
  - #194: old code recorded `save_donut` with `"tags":[]` — untagged = unreachable, observed at event level
  - #195: two tests FAIL on old code where declared keys and embed dependencies broke each other
  - #191: old code hijacked the app's logger binding (FAIL); writes after `__unserialize` restore errored on an uninitialized property
- Demo logs are committed under `demo/logs/` (4 files, 5,602 lines total; every demo exits 0 and passes offline schema validation)
- Portability: ext-redis / Predis and POSIX / Windows message differences are pinned as portable contract assertions

## 6. Declared boundaries — what the log does not record

- The CDN's own behavior (what the edge actually held or purged) is outside the log; it records what was *sent* to the CDN
- Memcached `pool_error` is verified with a closed-port probe only for Redis; the wiring is symfony's shared mechanism
- Recording is off by default (`NullSemanticLogger`); a healthy session was measured to contain no failure entries

## 7. Summary

In 1.16.2, when "the cache doesn't work" or "stale content survives", the log held a run of misses unrelated to the cause. In the next release, every decision — store, read, invalidation, 304, CDN, failure — is recorded as a typed event at the site where it becomes true, and the log's honesty itself is tested via published schemas, self-verifying demos, and mutation-selected pins. All twelve previously silent failure and decision classes (§1) now have a recording path or a fix.
