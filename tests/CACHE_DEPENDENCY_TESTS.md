# Cache Dependency Test Coverage

This document describes how cache dependency resolution is tested across the test suite.

## Dependency Patterns Tested

### Chain Dependencies (A → B → C)

When resource A embeds B, and B embeds C, purging C invalidates both B and A.

```
LevelOne → LevelTwo → LevelThree
purge(LevelThree) → LevelOne invalidated
```

**Test:** `CacheDependencyTest::testDestroyByGrandChild` (manual purge) and
`CacheDependencyTest::testWriteToGrandChildCascadesInvalidation` (the same cascade
driven by a write command: `LevelThree::onPut` carries `#[Purge]`)

### Parent-Child Dependencies

Purging a child invalidates its parent.

```
LevelOne → LevelTwo
purge(LevelTwo) → LevelOne invalidated
```

**Test:** `CacheDependencyTest::testDestroyByChild`

### Multiple Parents Depending on Same Child

When multiple parents embed the same child, purging the child invalidates all parents.

```
ParentA ──┐
          ├──→ ChildC
ParentB ──┘
purge(ChildC) → ParentA, ParentB both invalidated
```

**Test:** `CacheDependencyTest::testMultipleParentsDependOnSameChild`

### Unrelated Resources Independence

Resources in separate dependency chains do not affect each other.

```
Chain A: LevelOne → LevelTwo → LevelThree
Chain B: ChildC (independent)
purge(LevelThree) → LevelOne invalidated, ChildC unchanged
```

**Test:** `CacheDependencyTest::testUnrelatedResourcesAreIndependent`

### Embed-Based Dependencies with Donut Cache

Blog posting embeds comment; purging comment invalidates blog posting.

```
BlogPosting → Comment
purge(Comment) → BlogPosting invalidated
```

**Test:** `DonutRepositoryTest::testCacheDependency`

### Tag-Based Invalidation

Resources can be invalidated by their URI-derived tags.

```
invalidateTags([uriTag('page://self/html/blog-posting')]) → BlogPosting invalidated
```

**Test:** `DonutRepositoryTest::testInvalidateTags`

## Component Unit Tests

| Component | Test File | Coverage |
|-----------|-----------|----------|
| `UriTag::__invoke()` | `UriTagTest::testInvoke` | URI to tag string conversion |
| `UriTag::fromAssoc()` | `UriTagTest::testFromAssoc` | Generate tags from array data |
| `SurrogateKeys` | `SurrogateKeysTest` | Aggregate tags from multiple resources |
| Tree helpers | `SemanticLogTreeTrait` | Validate + collect types / depth from the log tree |

## Observability (Koriym.SemanticLogger)

Cache behavior is observed through [Koriym.SemanticLogger](https://github.com/koriym/Koriym.SemanticLogger):
an **open / event / close** model whose nested structure *is* the embed/dependency
tree. A resource GET opens a scope (`CacheInterceptor` / `AbstractDonutCacheInterceptor`);
embedded child GETs nest under it; the scope closes with the hit/miss outcome.
Saves, dependencies and invalidations are recorded as events inside the active scope.
Typed `AbstractContext` subclasses live in `src/Log/Context/` and each carries a
`SCHEMA_URL` resolved against `docs/schemas/context/`.

| Context (type) | Kind | Emitted by | Meaning |
|----|----|-----------|---------|
| `get` | open | `CacheInterceptor`, `AbstractDonutCacheInterceptor` | A resource/donut GET scope (children nest under it) |
| `cache_hit` / `cache_miss` (`layer`) | close/event | interceptors, `DonutRepository` | The lookup outcome (`layer`: resource / donut / donut-view) |
| `command` (`method`/`annotations`/`source`) | open | `CommandInterceptor`, `DonutCommandInterceptor`, `RefreshInterceptor` (all via the shared `CommandContextFactory`) | A write scope; `source` names the producing interceptor, `annotations` its `#[Refresh]`/`#[Purge]` attributes (empty on the CacheableResponse path by design) |
| `depends_on` (`parent`/`child`/`childTags`) | event | `CacheDependency::depends()` | A dependency-graph edge |
| `save_value` / `save_view` / `save_etag` / `save_donut` / `save_donut_view` | event | `ResourceStorage` | What was stored, with `tags`, `ttl` (seconds until expiry; 0/null = no expiry set) and the `saved` outcome |
| `invalidate` (`tags`/`roPool`/`etagPool`/`cdn`/`durationMs`) | event | `ResourceStorage::invalidateTags()` | Per-target outcome as status words: `roPool`/`etagPool` are `invalidated`\|`failed`; `cdn` is tri-state: `purged` (a configured purger ran), `failed` (the purge threw — fail-closed: local pools are invalidated first, then the exception propagates), `skipped` (NullPurger, no CDN configured). Pre-write cleanup vs real invalidation is decided by same-scope tag correlation — see the flow example below |
| `purge` | event | `QueryRepository::purge()` | An explicit purge request |
| `put_skipped` (`uri`/`reason`[/`code`]) | event | `CacheInterceptor`, `AbstractDonutCacheInterceptor`, `DonutRepository` | A miss was not followed by a put (`reason`: `etag-present` / `error-code` with the actual response `code` / `not-cacheable` for a donut page served from its template) |
| `cache_error` (`uri`/`operation`/`error`) | event | `CacheInterceptor`, `AbstractDonutCacheInterceptor` | The cache layer itself threw (e.g. cache server down); `operation` is the failing side (`read` / `write`); a `cache_miss` after it is a degraded cache, not a cold one |
| `put_donut` / `refresh_donut` | event | `DonutRepository` | Donut store / re-render from a template hit |
| `log_session_broken` (`reason`) | open/close | `SafeSemanticLogger::flush()` | Sentinel: the previous logging session was broken (e.g. LIFO violation) and its records were discarded; the flush containing it holds ONLY this scope — that window's cache activity is unknown, not absent |

(SemanticLogger derives entry ids as `{type}_{n}` and constrains them to
`^[a-z_]+_[0-9]+$`, so context `type`s use underscores; the donut `layer` value
`donut-view` is a field value, not an id, so it keeps its hyphen.)

### Reading the tree (human + AI)

`php demo/run-dependency.php` renders the session with `vendor/bin/stree`'s
`Stree\TreeRenderer`. The 3-level chain appears as native nesting — the embed
structure is the log structure, no reconstruction. Within a node the JSON groups
nested `open`s separately from `events` (they are not interleaved
chronologically); the emission order is: the leading `invalidate` (pre-write
cleanup — every `QueryRepository::doPut()` deleteEtags first), then the nested
child GET scopes run to completion, then the parent's `depends_on` / `save_*`
(the parent materializes its embeds before it can register and store):

```text
get uri=page://self/dep/level-one
├── invalidate tags=[_dep_level-one_] [event]            (pre-write cleanup)
├── get uri=page://self/dep/level-two
│   ├── invalidate tags=[_dep_level-two_] [event]        (pre-write cleanup)
│   ├── get uri=page://self/dep/level-three
│   │   ├── invalidate → save_etag → save_value [events]
│   │   └── (close) cache_miss layer=resource
│   ├── depends_on parent=.../level-two child=.../level-three [event]
│   ├── save_etag → save_value [events]
│   └── (close) cache_miss layer=resource
├── depends_on parent=.../level-one child=.../level-two childTags=[_dep_level-two_, _dep_level-three_] [event]
├── save_etag uri=.../level-one tags=[...] saved=true [event]
├── save_value uri=.../level-one tags=[..., _dep_level-three_] ttl=31536000 saved=true [event]
└── (close) cache_miss layer=resource
```

The leading `invalidate` uses the resource's own URI tag, which is also its
parents' surrogate key — so a child refill visibly purges the parent entry
before both are rebuilt, by design.

Pre-write cleanup vs real invalidation is decided by tag correlation, not by
scope type: an `invalidate` is pre-write cleanup when, within the SAME scope's
event stream, a later `save_*` event's `tags` include the invalidate's tags —
regardless of the enclosing scope type (`get` or `command`; a `#[Refresh]`
command's second put() runs inside the command scope, so a cleanup invalidate
can appear there too). `depends_on` events for the same resource may appear in
between (`QueryRepository::doPut()` runs deleteEtags, then setCacheDependency,
then the saves). In donut scopes match against `save_etag`/`save_donut_view`:
`save_donut`'s tags may exclude the URI tag (a known ordering limitation).
Note the tree JSON does not interleave a scope's events with its nested scopes
chronologically — within one scope events are time-ordered, but across the
events/open-children boundary no shared sequence exists; use scope nesting and
the next GET's hit/miss as ground truth for final state.

A write request opens a `command` scope (`method=onPut`, its `#[Refresh]`/`#[Purge]`
annotations) with the resulting `purge` / `invalidate` events nested beneath — so
the cause and the verified effect are both in one subtree. Scenario 3 of
`demo/run-dependency.php` demonstrates exactly this: a PUT on level-three (whose
`onPut` carries `#[Purge]`) drives the cascade, while scenario 6 shows the other
entry kind — a direct `purge()` call rooted in a top-level `manual_purge` scope.

## Schema Validation (Drift Detection)

`SemanticLogTreeTrait::flushAndValidate()` flushes the logger and runs
`Koriym\SemanticLogger\SemanticLogValidator` against `docs/schemas/context`,
validating every context against its `SCHEMA_URL`. It runs in the `tearDown()` of
the major cache tests, so any divergence between an emitted context and its schema
fails the suite immediately.

`SemanticLogSchemaTest` pins the contract from both sides:

| Test | Verifies |
|------|----------|
| `testDependencyChainValidatesAndNestsAsEmbedTree` | A real dependency run validates and nests ≥3 deep, with `cache_miss`/`depends_on`/`invalidate`/`save_value` present |
| `testCommandScopeRecordsCausality` | A write opens a `command` scope recording `onPut` and its annotations |
| `testNon200GetLogsPutSkippedWithActualCode` | A non-200 GET records `put_skipped` with `reason=error-code` and the actual response `code`, plus a `purge` event |
| `testValidatorRejectsContextViolatingItsSchema` | A `cache_hit` without `layer` is rejected (proves drift is caught) |

`ResourceStorageTest` pins the invalidation outcome and `GracefulLoggingTest`
pins resilience:

| Test | Verifies |
|------|----------|
| `testInvalidateTagsWithNullPurgerLogsCdnSkipped` | With the default NullPurger (no CDN) `cdn` is `skipped`; `roPool`/`etagPool` are `invalidated`, `durationMs` is recorded |
| `testInvalidateTagsLogsCdnPurgedWithConfiguredPurger` | A configured purger that runs without error logs `cdn` = `purged` |
| `testInvalidateTagsFailsClosedWhenPurgerFails` | A CDN purger outage is logged as `cdn=failed` after local invalidation, then the purge exception propagates (fail-closed) |
| `SafeSemanticLoggerTest::testRecoversToFreshSessionAfterFlushFailure` / `testLifoViolationBreaksSessionThenFlushRecovers` | A discarded session flushes to a `log_session_broken` sentinel (not silent-empty); the next session logs normally |
| `GracefulLoggingTest::testCacheWorksWhenLoggerAlwaysThrows` | A logger that throws on every call never breaks cache reads/writes (SafeSemanticLogger) |

## ETag Invalidation Verification

All dependency tests verify both resource cache and ETag invalidation:

| Test | ETag Assertions |
|------|-----------------|
| `testDestroyByChild` | Parent ETag invalidated |
| `testDestroyByGrandChild` | All 3 levels' ETags invalidated |
| `testUnrelatedResourcesAreIndependent` | Invalidated ETag gone, unrelated ETag preserved |
| `testMultipleParentsDependOnSameChild` | Both parents' ETags invalidated |

## Known Limitations (Deliberate Scope)

- **Request-end flush / concurrent long-running runtimes.** The logger is an injector
  singleton with a stack-based session, and (as before this migration) this package does
  not flush/reset per request. Safe operation therefore requires recreating the
  injector/logger per request OR flushing at each request boundary; under
  Swoole/RoadRunner a host must flush at the boundary itself. Under *concurrent*
  coroutines sharing the one singleton, an interleaved open/close violates LIFO and
  `SafeSemanticLogger` marks the session broken — so the current request's log is
  **discarded** and its flush returns only a `log_session_broken` sentinel (the
  wipe is visible, not silent). Cache behavior is unaffected
  (logging is a best-effort side-channel) and the next `flush()` recovers a fresh
  session (see `SafeSemanticLoggerTest`). Making the logger request/coroutine-scoped
  (so concurrent sessions cannot cross-nest or drop) is the robust fix and is
  intentionally deferred to the host flush-lifecycle work.
- **Donut-view hit vs. rebuild.** The donut GET scope closes as `cache_hit` (layer
  `donut-view`) whenever a ResourceObject is served — including when it was rebuilt
  from a cached donut template (the close reports only the final layer's outcome,
  also stated in `cache_hit.json`). The two are still distinguishable by the presence of a
  `refresh_donut` event inside the scope; the close label is intentionally coarse.
  When the page is not entire-content cacheable, no page-level save follows the
  rebuild — recorded as `put_skipped` with `reason=not-cacheable`.
- **Legacy `RepositoryLoggerInterface` receives no events.** Internal cache code logs
  through `SemanticLoggerInterface`; the deprecated flat interface stays bound for code
  BC but its instance stays empty. Consumers should migrate to the SemanticLogger tree.

## Fake Resources for Testing

Located in `tests/Fake/fake-app/src/Resource/Page/Dep/`:

| Resource | Embeds | Purpose |
|----------|--------|---------|
| `LevelOne` | `LevelTwo` | Top of 3-level chain |
| `LevelTwo` | `LevelThree` | Middle of chain |
| `LevelThree` | - | Leaf node; `onPut` carries `#[Purge]` (command-driven cascade, demo scenario 3) |
| `ParentA` | `ChildC` | Multiple parent test |
| `ParentB` | `ChildC` | Multiple parent test |
| `ChildC` | - | Shared child resource |
