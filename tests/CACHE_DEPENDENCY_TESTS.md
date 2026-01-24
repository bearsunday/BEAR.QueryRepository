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
