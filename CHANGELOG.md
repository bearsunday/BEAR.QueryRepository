# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `UncacheableMarkInterface::mark($reason)`: code that produces a per-user value (a CSRF token, etc.) marks the current response unstorable; it is then not cached (`put_skipped{reason: unstorable}`) and is sent with `Cache-Control: private, no-store` and `Pragma: no-cache` and no CDN headers (#222). This is the app-facing interface's only method, enforced at RUNTIME, not just by static analysis: it resolves to `UncacheableMarker`, a thin wrapper with no other method, around the package-internal `UncacheableMarkScopeInterface` (which `UncacheableMark` implements instead - never the app-facing interface). An application's own resource, injected `UncacheableMarkInterface`, cannot call `enterWrite()`/etc even if it tried - there is no such method on the object it was handed.
- `UncacheableMarkScopeInterface` (bound `Scope::SINGLETON`) and `DevUnstorableGateModule`'s observer are both request-scoped: a long-running worker (Swoole, RoadRunner) that reuses one process across requests must give each request its own fresh `UncacheableMarkScopeInterface` instance - and ONLY that key, exactly as it already must for `ServerContextInterface` - by building that request's own `Injector`/object graph; `$module->override()`/rebinding has no effect on an already-built `Injector` (verified by execution), so a fresh instance appears only from a fresh one. `UncacheableMarkInterface` itself needs nothing done for it (freshly constructed, not singleton, on every resolution, it always wraps whichever `UncacheableMarkScopeInterface` instance is currently bound) and must NEVER be handed a stateful instance of its own - doing so decouples it from whatever `UncacheableMarkScopeInterface` instance the rest of the package actually consults, the same stale-state leak as reusing the wrong instance risks.
- `DevUnstorableGateModule`: `$module->override(new DevUnstorableGateModule())` reports a skipped write as a thrown `Exception\UnstorableResponseDetected`, once, after the response finishes rendering (`install()` has no effect here).

### Changed
- Deploy this version with a new `CacheVersionModule` version (an app that binds its own cache adapter rather than this package's `Storage*Module`s must clear its pools instead): entries written by earlier versions may hold per-user content, and an earlier version reading this version's donut cache shows raw `[le2:...]` placeholders during a rolling deploy.
- A `#[Cacheable]`/`#[CacheableResponse]` response whose own code sets `Cache-Control` to `private` or `no-store` is no longer stored server-side; its header is kept and CDN headers are removed. A value declared by `#[HttpCache]`/`#[NoHttpCache]` is still stored.
- `DevUnstorableGateModule` now binds `UnstorableWriteObserverInterface` (`ThrowingUnstorableWriteObserver`) instead of decorating the gate: it reports a skipped write once after rendering instead of throwing mid-render, and a hole legitimately refusing its own write (e.g. a CSRF form hole on an otherwise-correct `#[DonutCache]` page) is no longer reported. `ThrowingUnstorableGate` is removed.
- Upgrading changes the Ray.Aop-woven class name - and so the ETag (`crc32($ro::class . ...)`) - for a `#[CacheableResponse]` class or method that also declares an `#[Embed]`, because it is now woven with a new interceptor (#226); a page with no `#[Embed]` keeps its stock woven class and ETag, and a `#[DonutCache]` page never carries an ETag at all, so neither is affected.
- `ResourceDonut::FORMAT` is no longer public; use `ResourceDonut::placeholder()`. `ResourceDonut::refresh()` takes an additional `UncacheableMarkScopeInterface` argument (package-internal - see the Added entry above).
- Requires `koriym/semantic-logger` `^0.11`. Written logs use its `log` document shape, and diagnostic `kind` is now `code`.
- Requires `bear/resource` `^1.22.4`.
- `KeepMutationsAndFailures` also keeps a session cut at the record limit (`semantic_logger_truncated`): a `command` past the limit leaves no other trace.
- `ResourceDonut::refresh()`: a hole that throws while an already-cached `#[DonutCache]` page is recomposed (its own `onGet()` or its render) no longer lets that exception reach the caller directly from the refresh. It now degrades the same way a cache-store failure does and falls back to rendering the whole page fresh. A transient failure (the hole recovers on that fresh render) now answers 200 with the full body instead of an error - for example a corrupted legacy placeholder's `ParameterException`, previously an uncaught 500 straight from the refresh, now answers 200 on both this request and the next, since the fresh render re-stores the template with a correct `[le2:...]` placeholder. A hole that keeps throwing still fails the request - from the fresh render instead of from the refresh - exactly as the page would without the cache attribute at all.
- `CacheDependency::depends()` no longer unsets the child's own `Surrogate-Key` header after copying it into the parent: a child that is both read by the parent and later rendered as a hole keeps its own key for its own independent cache entry.

### Fixed
- `LogFileWriter`: two sessions flushed in the same microsecond no longer overwrite each other. The timestamped filename now carries a random suffix, so both survive (#221).
- A `#[DonutCache]` page embedding an `#[Embed]` hole whose URI carried two or more query parameters leaked a raw `[le:...]` placeholder to the client and could throw `ParameterException` on the next request - fixed by moving new placeholders to a `[le2:...]` marker (#232).
- An exception while rendering a `#[DonutCache]`/`#[CacheableResponse]` page - a hole's own resource or the page's own template - no longer stores an empty template that was served to every later visitor (since 1.17.0). The page's own template failure now surfaces exactly as it would for the same page without the cache attribute, instead of being swallowed into an empty page; a hole's failure now surfaces as a clean, catchable exception too - actually a better outcome than an `#[Embed]` of the same resource gets outside a `#[DonutCache]` page, where BEAR.Resource's own `AbstractRequest::__toString()` swallows it into a fatal `E_USER_ERROR` instead.
- A degraded cache read (a down cache pool, or - see above - a hole failing while a cached page is refreshed) now actually renders its fresh-render fallback instead of returning the un-rendered result of running `onGet()` alone: previously such a request answered 200 with an unresolved `#[Embed]` hole still in its body and no view, rather than the composed page.
- An embedded child of a `#[DonutCache]`/`#[CacheableResponse]` page that `onGet()` already read into the body is no longer re-executed when the page renders it as a hole; both now come from the same single execution (#226).
- `ResourceBodyEvaluator` no longer crashes materializing an embedded child whose `jsonSerialize()` does not answer the memoized `ResourceObject` (the shape `BEAR\Async\AsyncRequest` overrides it to): such a child is now left unmaterialized instead of asserting. Async support itself remains out of scope.
- A `mark()` no longer refuses an unrelated `#[Cacheable]` write later in the same request; `QueryRepository::doPut()`'s own write check is now scoped to the marking resource's own subtree (`isMarkedForCurrentScope()` OR'd with a new `wasMarkedSinceCurrentWrite()`), not "marked anywhere" (#234). `#[CacheableResponse]`'s own template write (`DonutRepository::doPutStatic()`) is unchanged, still any-owner - a hole's mark still refuses the whole rendered page, by design. A direct `QueryRepository::put()` call stays conservative and is refused after any `mark()` in the request; this scoping applies only to writes made by the `#[Cacheable]` interceptor.
- `DevUnstorableGateModule`'s observer no longer replaces a genuine exception (from the resource's own execution, a render, or the store) with `Exception\UnstorableResponseDetected` when both happen in the same request: `CacheInterceptor`/`AbstractDonutCacheInterceptor` only raise a pending skip after their own finally returns normally, and drop it (via the new `UnstorableWriteObserverInterface::clearPending()`) rather than raise it when a real exception is already propagating, so a later request sharing the same (SINGLETON) observer never inherits a stale skip either.
- `HeaderSetter`'s own "was this refusal actually caused by a mark" check no longer asks its own `UncacheableMarkScopeInterface` dependency (removed entirely) - both callers now compute the answer with the SAME predicate that decided `$unstorable` and hand it in as a required `$wasMarked` argument: `QueryRepository::doPut()` passes `UnstorableGateInterface::isMarkReasonForOwnWrite()`'s own-write-scoped answer, `DonutHeaderSetter` passes the new `UnstorableGateInterface::isMarkReason()`'s any-owner one (unchanged behaviour, matching its own any-owner `reason()` check). Omitting `$wasMarked` when `$unstorable` is given now throws a `LogicException` immediately, rather than silently falling back to a predicate that could disagree with whichever one decided the write was unstorable in the first place (the bug this replaces): a `#[Cacheable]` resource that hand-set its own `Cache-Control: private, max-age=60` (refused for that reason alone, no mark of its own) no longer has that header wrongly replaced with `private, no-store` + `Pragma: no-cache` whenever an unrelated mark fires anywhere else in the same request.
- `QueryRepository::put()` reached with NO cacheable GET interceptor active at all (a direct, non-AOP call from a CLI script, a command handler, anything outside a GET flow) now reports its own pending dev-gate skip immediately after returning, instead of leaving it pending in the (SINGLETON) `UnstorableWriteObserverInterface` for a LATER, unrelated request's own interceptor to inherit as a stale `Exception\UnstorableResponseDetected`: no outermost `CacheInterceptor`/`AbstractDonutCacheInterceptor` was ever on the call stack to report it otherwise. A direct `put()` reached WITH an interceptor already active (a direct put() made from inside a resource's own `onGet()`) is unaffected, still deferring to that interceptor's own outermost completion.
- A `#[Cacheable]` resource's `onGet()` that stored a per-user value with a direct, non-AOP `QueryRepository::put($this)` call and only `mark()`ed it afterward kept that value cached when `CacheInterceptor`'s own cache-pool read failed first (a degraded read): the fallback returned the raw, un-rendered result directly, skipping the outer write - and the pre-write cleanup (`deleteEtag()`) that write always runs - that a normal cache miss would have reached and that would have removed the secret the inner write already stored. The degraded-read fallback now runs through the exact same write path a miss takes, so it reaches the same end state either way.
- The same `onGet()` pattern (a direct `put($this)`, `mark()` afterward) also leaked on a `#[DonutCache]`/`#[CacheableResponse]` page, on its normal path as well as a degraded read: `AbstractDonutCacheInterceptor`'s own "already answered" shortcut treats any `ETag` already present on the response as the resource's own, legitimately pre-set validator and skips the donut write (and its cleanup/header-refusal) entirely - but `QueryRepository::doPut()`'s own header handling sets an `ETag` as a side effect of the inner direct write too, which was indistinguishable from the resource's own one. `AbstractDonutCacheInterceptor` now applies the same cleanup/header-refusal `putStatic()`/`putDonut()`'s own write would on that one shortcut path - two new, appended, DI-injected constructor dependencies (`UnstorableGateInterface`, `HeaderSetter`) and the already-public `DonutRepositoryInterface::purge()` for the cleanup itself, so a third-party `DonutRepositoryInterface` implementer is unaffected (no new interface method).
- That same shortcut's own refusal did not distinguish a descendant hole legitimately refusing its OWN write (expected, never reported by the normal write path's own `reasonExcludingHoleMarks()`) from this page's own mark: a request where some EARLIER hole elsewhere in the tree already marked and closed before this page's own shortcut-path refusal ran newly raised `Exception\UnstorableResponseDetected` under `DevUnstorableGateModule`, where the normal write path never would have for the equivalent case. Symmetry/defense-in-depth, not a gap a hole embedded directly in this SAME page could trigger: a hole scope is only ever opened by `DonutRequest` inside `ResourceDonut::create()`, the normal write path this shortcut specifically bypasses, so no render - and no hole embedded in this page - ever runs here. The shortcut now splits the same two predicates `doPutDonut()` does - `reason()` (any-owner) still decides the header refusal and cleanup, `reasonExcludingHoleMarks()` alone decides whether the dev gate is told.
- The same shortcut also newly reported an error-code (4xx/5xx) response to the dev gate whenever it happened to ALSO be marked: a top-level page marking itself (a CSRF token) and separately answering 400 for a validation error now raises nothing - an error-code response was never dev-gate-checked at all before this shortcut's own cleanup/refusal existed, and still is not.
- A `#[DonutCache]`/`#[CacheableResponse]` page whose `onGet()` reads an embedded child's value now carries that child's tags, so purging the child also drops the page's stored template instead of leaving it stale. A hole the page only renders still just recomposes on purge.
- `DonutRenderer`: a second cache MISS of the same page in one long-running container (Swoole, RoadRunner) now renders that hole's fresh content; it previously could replay the first MISS's stale view.

## [1.17.0] - 2026-09-17

### Added
- **Cache observability rebuilt on [Koriym.SemanticLogger](https://github.com/koriym/Koriym.SemanticLogger)** (#178): typed, schema-validated open/event/close log tree replaces free-text `RepositoryLoggerInterface` messages. Off by default - install `DevQueryRepositoryLogModule` (dev) or `ProdQueryRepositoryLogModule` (prod) to record. See `docs/reading-the-log.md`, `docs/why-the-log-records-everything.md`, `docs/what-the-log-proves.md` (Japanese translations included).
- New log contexts: `cache_policy` (#186), `cache_error`, `pool_error`, `put_skipped`, `pre_write_cleanup`, `cdn_headers`, `conditional_request`, `invalidate` (tri-state `cdn`, plus `durationMs`), `command` (`source` field), `semantic_logger_error` (core). Schemas in `docs/schemas/context/`.
- New fields on existing save contexts: `saved`, `tags`, `requestedTtl` (`save_etag`), `durationMs` on the `cache_hit`/`cache_miss` close.
- `#[CacheLog]` qualifier resolves the cache logger. `SafeSemanticLogger`/`SessionStoreInterface`/`ProcessSession` keep it safe across a serialized injector (#179). `TopLevelAwareInterface` lets a custom logger opt into manual-call scope rooting.
- Direct (non-AOP) `put()`/`putStatic()`/`putDonut()`/`purge()`/`invalidateTags()` calls now open `manual_store`/`manual_purge`/`manual_invalidate` scopes.
- `LogSinkInterface`/`ShutdownFlush`, `LogWriterInterface` (`LogFileWriter`/`LogStreamWriter`/`PsrLogWriter`), `ConcurrentRuntimeInterface`/`HostRuntime` (refuses to arm under RoadRunner/a Swoole coroutine). Detection is not exhaustive: FrankenPHP worker mode, ReactPHP, Amp, a long-lived CLI consumer, and a Swoole worker whose logger is built outside a coroutine must bind their own `ConcurrentRuntimeInterface`/`SessionStoreInterface`/`LogSinkInterface`.
- `ProdQueryRepositoryLogModule`: buffers a session and applies `RetentionPolicyInterface`/`KeepMutationsAndFailures` at flush.
- `DevQueryRepositoryLogModule`: writes one file per request plus `latest.json` for `vendor/bin/stree`.
- `demo/run-degraded.php` and `DemoLogCoverageTest`: the demos now cover every context, schema enum value and command source, and self-validate against `docs/schemas/context`.
- `UriScopedHttpCacheInterface::isNotModifiedFor()` / `ScopedValidatorInterface::hasEtagFor()`: opt-in ETag scoped to the requested URI (#197, #201). Entries written before this version cannot be scoped: each client pays one full (non-304) response after the upgrade, once.
- Negative TTL is clamped to 0 at the `QueryRepository`/`ResourceStorage` boundary.
- Dependency evidence in `docs/reading-the-log.md` is now stated per parent declaration (#188).

### Deprecated
- `RepositoryLogger`, `RepositoryLoggerInterface`, `StructuredRepositoryLoggerInterface`, `NullRepositoryLogger`: bound for BC but receive no internal events.
- The `update` parameter of `#[Cacheable]`: it has no effect.

### Removed
- `docs/schemas/repository-log.json` (superseded by per-context schemas in `docs/schemas/context/`).
- `BEAR\QueryRepository\Log\NullSemanticLogger` (koriym/semantic-logger 0.9 ships its own).
- `skills/bear-cache-log/`: folded into [bear-observe](https://github.com/bearsunday/BEAR.EventSourcing/blob/1.x/skills/bear-observe/SKILL.md).

### Changed
- Cache logging call sites now emit typed contexts through `SemanticLoggerInterface`/`#[CacheLog]` instead of `RepositoryLoggerInterface::log()`.
- `SaveDonutContext`/`SaveDonutViewContext`: `sMaxAge` field renamed to `requestedTtl`.
- `SaveEtagContext`/`SaveDonutViewContext`: `surrogateKeys` field renamed to `tags`.
- A failed command write (4xx) now closes `command_result` instead of vanishing from the log.
- Removed the post-save `assert()` in `ResourceStorage::saveDonut()` (it threw after `saved: false` was already logged).
- Pre-write cleanup is recorded at the source (`pre_write_cleanup` marker) instead of inferred from tag correlation.
- A donut refresh no longer appends an `r` marker to the ETag, so an unchanged recomposition still revalidates via `If-None-Match`.
- New runtime dependency: `koriym/semantic-logger`.
- **Breaking**: recording is off by default - `#[CacheLog]` binds to `NullSemanticLogger` unless a log module is installed.
- **Breaking** (#190): `Exception\CacheStoreFailure` now marks the cache-failure boundary. An unreadable ETag pool answers the conditional request in full instead of failing it, and a donut write the store refuses now serves the rendered page instead of a 500.
- **Breaking** (#208): a CDN purge failure now fails closed. Local pools invalidate first, the outcome is logged as `invalidate.cdn: failed`, then the purge exception propagates instead of being swallowed - a CDN outage can no longer look like a successful purge.
- `#[Refresh]` no longer double-writes its own `#[Cacheable]` destination after a regenerating GET.
- **Breaking**: renamed `ResourceDonut::FOMRAT` → `FORMAT`, `EtagSetter::getEtagByEitireView()` → `getEtagByEntireView()`, and the misspelled parameters `$httpCacche` → `$httpCache` (`EtagSetter::getEtagByPartialBody()`), `$concheControlMaxAge` → `$cacheControlMaxAge` (`HeaderSetter::__invoke()`) - a named-argument caller has to follow.

### Fixed
- Donut caching could not handle a non-200 response: `saveView()` required a 200-only validator, and `DonutRepository`/`ResourceDonut` did not restore the stored status code (#206, #207). Behaviour change: a `#[CacheableResponse]`/`#[DonutCache]` page answering 2xx or 3xx is now served with that status instead of crashing or degrading to 200 - a redirect that was reachable only once now persists until its cache entry is invalidated, so such a page needs the surrogate keys that invalidate it.
- `onPost` on a `#[Cacheable]` class ran with no interceptor at all, silently dropping `#[Refresh]`/`#[Purge]` (#212, #214).
- `onPost` on a `#[Cacheable]`/donut class missing an `onGet`-required parameter threw `UnmatchedQuery` uncaught - a regression from #214 above (#219, #220).
- A write could be answered from cache without running, when a command method carried `#[RefreshCache]` or a method-level `#[CacheableResponse]`; both now bind `DonutCommandInterceptor`. `DonutCacheModule` also missed `onPost` in its write matcher.
- `DevEtagSetter`/`MobileEtagSetter` set a validator on a non-200 response, where `EtagSetter` always skipped it; `CdnCacheControlHeaderSetterInterface` likewise now applies to 200 only.
- A client `If-None-Match` token containing a PSR-6 reserved character reached the ETag pool as a cache key and threw a 500; such tokens (and `*`) are now dropped and the request answered in full.
- An embedded child was rendered twice per parent store instead of reusing the execution the renderer already paid for.
- A donut refresh advanced `Last-Modified` even for byte-identical content; `Age` is now derived from `storedAt` instead of `Last-Modified`.
- `putStatic()`/`putDonut()` logged a negative lifetime verbatim while storage clamped what it stored.
- A donut write failure on the AOP path (pool outage, renderer error) is now recorded as `cache_error{operation: write}`.
- **Behaviour change for 1.16.x installations**: fixed a 1.16.0 regression where a resource declaring its own `Surrogate-Key` lost embed dependency tracking and kept serving purged children.
- Deduplicated the `Surrogate-Key` header when a resource is written twice in one request.
- `ResourceStorage::saveEtag()` used a hard-coded `new UriTag()` instead of the injected `UriTagInterface`.
- **Behaviour change for existing installations** (#185): a donut template with no `Surrogate-Key` was untagged and unreachable by `purge()`; it now rebuilds on its first purge. Entries written before this version need a rewrite or a cache clear to become reachable.
- A `#[Cacheable]` value entry with no renderer previously degraded every store to a warning and left the cache empty; it is now stored without rendering. The ETag validator falls back from the view to the body, a stored value entry no longer carries the renderer's `Content-Type`, and a custom `EtagSetterInterface` now receives `$ro->view === null` on the value path and must read the body instead.
- A value-entry body PHP cannot `serialize()` (a `Closure`, for instance) is a separate, known limitation: the write's `serialize()` `Exception` propagates uncaught rather than degrading to `CacheStoreFailure` (#208's rule: only the store's own failure is swallowed). Keep such values out of a value entry's body, or catch `Exception` at the call site.
- `CliHttpCache::isNotModifiedFor()` ignored the CLI request form's `If-None-Match` argument.
- `MatchQuery` threw for an `onGet` parameter with a default value that a write's query omitted.
- Two donut placeholders on one template line were captured as a single URI by a greedy pattern.
- `CDN-Cache-Control` directives are now comma-separated per RFC 9213 (were space-separated).
- Under `AkamaiModule`, a `#[CacheableResponse]` page lost its embedded children's invalidation tags (`ResourceStorageInterface::saveDonutView()` gained an optional `$tags` parameter).
- `MobileEtagSetter` serialized the raw body and threw on any `#[Embed]`; it now hashes the materialized copy the way `EtagSetter` does.

## [1.16.2] - 2026-06-29

### Fixed
- Remove unused ETag from invalidation tags: ETag was unnecessarily registered as a cache invalidation tag. No code path invalidates by ETag (only by URI tag and surrogate keys), so each content version produced a new, non-volatile tag Set that was never read or reclaimed. Under `volatile-*` eviction policies these Sets leaked memory indefinitely. (#180)

## [1.16.1] - 2026-06-01

### Fixed
- Fix multi-embed cache dependency: a `#[Cacheable]` resource embedding more than one child kept only the last child's dependency, so purging an earlier child failed to invalidate the parent (stale cache). `CacheDependency::depends()` now accumulates child tags instead of overwriting, and the erroneous assertion that a parent had no prior tags has been removed.

## [1.16.0] - 2026-05-16

### Fixed
- Resolve embed cache dependencies before HAL rendering: child resources' ETag headers are now collected before `HalRenderer` strips `Request` instances from the body, so the parent's `Surrogate-Key` reliably includes all embedded children (#174)
- Include async embed children in dependency resolution: walk by `AbstractRequest` instead of the concrete `Request`, so `AsyncRequest` (and other `AbstractRequest` subclasses) are no longer silently skipped

### Changed
- Cache dependency resolution moved from `EtagSetter` / `DevEtagSetter` into `QueryRepository::put()` (runs on HTTP 200 only, before persistence)
- `QueryRepository::__construct()` now requires `CacheDependencyInterface`
- `EtagSetter::__construct()` and `DevEtagSetter::__construct()` no longer accept `CacheDependencyInterface`
- DI users via `QueryRepositoryModule` are unaffected; only callers that directly `new` these classes need to update their constructor calls

### Added
- HAL embed cache dependency test (`tests/CacheDependencyTest.php`)
- Non-Cacheable embed child test fixtures and `continue` path coverage

## [1.15.0] - 2026-02-03

### Added
- Add `ServerContextInterface` for coroutine-safe request handling in Swoole/RoadRunner environments
- Add `GlobalServerContext` as default implementation using `$_SERVER` superglobal
- Add `RepositoryLoggerInterface` with `reset()` method for long-running process support

### Changed
- Bind `ServerContextInterface` to `GlobalServerContext` in `QueryRepositoryModule`
- Use `ServerContextInterface` in `ResourceStorage` instead of direct `$_SERVER` access

## [1.14.0] - 2025-01-24

### Added
- Add LLM documentation (`docs/llms.txt`, `docs/llms-full.txt`) for AI-assisted development
- Add JSON schema for RepositoryLogger output (`docs/schemas/repository-log.json`)
- Add cache dependency demos for AI log analysis (`demo/run-dependency.php`, `demo/run-donut.php`)
- Add cache dependency test coverage documentation (`tests/CACHE_DEPENDENCY_TESTS.md`)
- Add test resources for cache dependency patterns (ParentA, ParentB, ChildC)

### Changed
- Change RepositoryLogger output to JSON format for structured logging
- Update `.gitattributes` to exclude development files from release
- Require `ray/aop` ^2.19.1 and `ray/di` ^2.20 for PHP 8.5 compatibility
- Update copyright year to 2026

### Fixed
- Fix UriTagTest typo in documentation

## [1.13.0] - 2024-11-11

### Added
- **Migration Tools**: Added `rector-migrate.php` for automated annotation-to-attribute migration
- **Migration Guide**: Added `ANNOTATION_TO_ATTRIBUTE.md` with comprehensive migration instructions
- Add CLAUDE.md with comprehensive codebase architecture and development guide
- Add marshaller configuration support for Redis with compression options (deflate)
- Add `MarshallerType` enum for type-safe marshaller selection
- Add support for `RelayCluster` in `RedisDsnProvider`
- Add Japanese README (README.ja.md)
- Add `#[Override]` PHP attribute across all applicable methods and classes
- Add Memcached EtagPool module with TagAwareAdapter support
- Add Redis DSN module (`StorageRedisDsnModule`) with provider implementation
- Add TagsPool annotation and binding to QueryRepositoryModule
- Add validation to ensure FastlyPurgeModule is installed when used
- Add Dependabot configuration
- Add PHP 8.4 support to CI workflow
- Add PHP 8.5 support to CI workflow

### Changed
- **PHP 8 Attributes Migration**: Removed `doctrine/annotations` and `doctrine/cache` dependencies, migrated to native PHP 8 attributes
- **Minimum PHP Version**: Updated requirement from PHP 8.1 to PHP 8.2
- Update development tools: PHP_CodeSniffer to 4.0, Doctrine Coding Standard to 14.0, Slevomat Coding Standard to 8.24, PHPUnit to 11.5
- Optimize readonly class declarations for PHP 8.2 (class-level modifier)
- Improve cache attribute and interceptor documentation with usage examples
- Improve `rector-migrate.php` to support vendor installation by removing hardcoded paths
- Update `ANNOTATION_TO_ATTRIBUTE.md` migration guide following Ray.AuraSqlModule pattern
- Improve marshaller provider error handling with better exception messages
- Enhance Memcached module with TagAwareAdapter support
- Update Symfony Cache to support version ^7.3
- Update `symfony/polyfill-php83` dependency to `^v1.32.0`
- Update `mobiledetect/mobiledetectlib` to support version ^4.8
- Update composer dependencies (`madapaja/twig-module`, `phpunit/phpunit`, `predis/predis`, `twig/twig`, `symfony/process`)
- Update `vimeo/psalm` to version 6.12
- Update `ray/aop` dependency to ^2.16
- Update `ray/di` dependency to ^2.17.2
- Set `ResourceStorage` to singleton scope for better performance
- Refactor `ResourceStorage` with `ProviderInterface` for serialization support
- Improve UriTag test coverage for consistent key generation across parameter order
- Normalize URI separators in generated cache keys for cross-platform compatibility
- Simplify ETag and surrogate key generation logic
- Handle both forward slashes and backslashes in surrogate key generation
- Sanitize ETags to replace reserved characters
- Replace symlink with actual file for cross-platform compatibility
- Enable package sorting in composer.json
- Refactor CI workflow for expanded PHP version and OS coverage
- Update copyright year to 2025

### Removed
- Remove Sodium marshaller related code
- Remove `bear/fastly-module` from production dependencies (moved to dev dependencies)
- Remove unnecessary singleton scopes from bindings
- Remove unused PSR cache annotations and RedisAdapter binding
- Remove unused MemcachedAdapter bindings
- Remove redundant `assert` statements from codebase
- Remove unused dependencies from composer.json

### Deprecated
- Deprecate `StorageApcModuleTest`
- Deprecate `StorageRedisModuleTest` (use `StorageRedisDsnModule` instead)
- Deprecate `StorageRedisMemcachedModule`
- Deprecate `BcModule`
- Deprecate `NamespacedCacheProvider` class
- Deprecate `ResourceStorageCacheableTrait`

### Fixed
- Fix type casting in headers
- Fix path separator replacement for cross-platform compatibility (Windows/macOS/Linux)
- Fix typo in deprecated notice
- Fix README file extension issue

## [1.9.9] - 2024-XX-XX
(Previous releases not documented yet)