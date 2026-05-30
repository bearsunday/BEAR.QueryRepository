# Cache Dependency Test Coverage

This document describes how cache dependency resolution is tested across the test suite.

## Dependency Patterns Tested

### Chain Dependencies (A → B → C)

When resource A embeds B, and B embeds C, purging C invalidates both B and A.

```
LevelOne → LevelTwo → LevelThree
purge(LevelThree) → LevelOne invalidated
```

**Test:** `CacheDependencyTest::testDestroyByGrandChild`

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
| `RepositoryLogger` | `RepositoryLoggerTest` | Log formatting with arrays |
| `RepositoryLogger::getOps()` / `getLogs()` | (used across cache tests) | Structural log assertions |

## Observability Events (Semantic Log)

Cache behavior is observable through the structured log emitted by
`RepositoryLogger` and defined by `docs/schemas/repository-log.json`. The
canonical outcome events let a human or an AI reconstruct what happened without
reading the implementation:

| Op | Emitted by | Meaning |
|----|-----------|---------|
| `cache-hit` (`layer`) | `CacheInterceptor`, `DonutRepository` | Lookup served from cache |
| `cache-miss` (`layer`) | `CacheInterceptor`, `DonutRepository` | Lookup not in cache |
| `depends-on` (`parent`/`child`/`childTags`) | `CacheDependency::depends()` | A parent now depends on a child (dependency-graph edge) |
| `refresh-trigger` (`method`/`annotations`) | `CommandInterceptor`, `RefreshInterceptor` | A command (e.g. `onPut`) and its `#[Refresh]`/`#[Purge]` annotations caused the invalidation that follows |
| `invalidate-etag` (`tags`/`roOk`/`etagOk`/`purgerOk`/`dur`) | `ResourceStorage::invalidateTags()` | Tags invalidated; records whether each pool succeeded and whether the best-effort CDN purge completed (`purgerOk=false` on outage, without failing local invalidation) |

The `layer` field distinguishes `resource` (plain `#[Cacheable]`), `donut-view`
(rendered donut page) and `donut` (donut structure). Hit/miss are the *outcome*;
`try-donut-view` / `try-donut` / `refresh-donut` remain as *process* markers.

### Reconstructing behavior from the log (AI-readable)

`php demo/run-donut.php` produces, on the comment purge + re-access step:

```json
{"op":"cache-miss","uri":"page://self/html/blog-posting","layer":"donut-view"}
{"op":"cache-hit","uri":"page://self/html/blog-posting","layer":"donut"}
{"op":"refresh-donut","uri":"page://self/html/blog-posting"}
{"op":"cache-miss","uri":"page://self/html/comment","layer":"resource"}
```

This reads unambiguously as: *the rendered page was stale (`donut-view` miss),
the stable donut shell was reused (`donut` hit), so only the dynamic `comment`
was regenerated (`resource` miss)* — the essence of donut caching, recoverable
from the log alone.

The *cause* of an invalidation is equally recoverable. A write request emits:

```json
{"op":"refresh-trigger","uri":"app://self/user?id=1","method":"onPut","annotations":[{"class":"...\\Purge","uri":"app://self/user/friend?user_id={id}"},{"class":"...\\Refresh","uri":"app://self/user/profile?user_id={id}"}]}
{"op":"purge-query-repository","uri":"app://self/user/friend?user_id=1"}
{"op":"invalidate-etag","tags":["_user_friend_user_id=1"],"roOk":true,"etagOk":true,"purgerOk":true,"dur":0.007}
```

= *`onPut` ran; its `#[Purge]`/`#[Refresh]` annotations caused these URIs to be
purged; the invalidation succeeded across both pools and the CDN.* Dependency
edges (`depends-on`), the triggering command (`refresh-trigger`) and the verified
outcome (`invalidate-etag` with `roOk`/`etagOk`/`purgerOk`) let the full cascade
in the tests below be reconstructed — and **verified** — from the log alone.

## Schema Validation (Drift Detection)

`tests/SchemaValidationTrait::assertLogValidatesSchema()` validates every emitted
log entry against `docs/schemas/repository-log.json` (Draft 2020-12, via
`opis/json-schema`). It runs in the `tearDown()` of the major cache tests, so any
divergence between the implementation and the published schema fails the suite
immediately.

`SchemaValidationTest` also pins the contract from both sides:

| Test | Verifies |
|------|----------|
| `testDependencyChainLogsValidateAgainstSchema` | A real dependency run emits only schema-valid entries (and includes `cache-hit`/`cache-miss`/`depends-on`) |
| `testRefreshTriggerRecordsCommandCausality` | A write emits `refresh-trigger` with the method and annotations |
| `testSchemaRejectsUnknownOperation` | An out-of-enum `op` is rejected (proves drift is caught) |
| `testSchemaRejectsCacheHitMissingLayer` | A `cache-hit` without `layer` is rejected |

`ResourceStorageTest` pins the invalidation outcome:

| Test | Verifies |
|------|----------|
| `testInvalidateTagsRecordsSuccessfulOutcome` | `roOk`/`etagOk`/`purgerOk` are `true` and `dur` is recorded |
| `testInvalidateTagsTreatsPurgerFailureAsBestEffort` | A CDN purger outage does not fail local invalidation; the failure is recorded as `purgerOk=false` |

## ETag Invalidation Verification

All dependency tests verify both resource cache and ETag invalidation:

| Test | ETag Assertions |
|------|-----------------|
| `testDestroyByChild` | Parent ETag invalidated |
| `testDestroyByGrandChild` | All 3 levels' ETags invalidated |
| `testUnrelatedResourcesAreIndependent` | Invalidated ETag gone, unrelated ETag preserved |
| `testMultipleParentsDependOnSameChild` | Both parents' ETags invalidated |

## Fake Resources for Testing

Located in `tests/Fake/fake-app/src/Resource/Page/Dep/`:

| Resource | Embeds | Purpose |
|----------|--------|---------|
| `LevelOne` | `LevelTwo` | Top of 3-level chain |
| `LevelTwo` | `LevelThree` | Middle of chain |
| `LevelThree` | - | Leaf node |
| `ParentA` | `ChildC` | Multiple parent test |
| `ParentB` | `ChildC` | Multiple parent test |
| `ChildC` | - | Shared child resource |
